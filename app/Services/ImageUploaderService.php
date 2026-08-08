<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploaderService
{
    /**
     * WebP conversion quality (0-100). Kept high enough for e-commerce images
     * while still delivering meaningful bandwidth savings over JPEG/PNG.
     */
    protected const WEBP_QUALITY = 85;

    /**
     * Raw image payloads above this size are skipped for WebP conversion.
     * GD decodes the whole image into memory, so an oversized JPEG on a
     * memory-constrained VPS would risk an out-of-memory kernel kill.
     */
    protected const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    /**
     * Download an external image, convert it to WebP and upload it to Cloudflare R2.
     *
     * Returns the permanent public R2 URL on success, null when the source is
     * already on R2, R2 is not configured, the download fails, or conversion fails.
     * Exceptions are swallowed so the caller's main flow never crashes.
     */
    public static function uploadToR2(Product $product, ?string $externalUrl = null): ?string
    {
        $imageUrl = $externalUrl ?: $product->image_url;

        $uploadedUrl = self::upload($imageUrl, self::buildFilename($product, $imageUrl));

        if ($uploadedUrl === null) {
            return null;
        }

        $product->image_url = $uploadedUrl;
        $product->saveQuietly();

        return $uploadedUrl;
    }

    /**
     * Upload an arbitrary image URL to R2 without touching any model.
     *
     * Useful when the target record does not exist yet (e.g. Filament create page).
     */
    public static function upload(?string $imageUrl, string $seed): ?string
    {
        if (blank($imageUrl)) {
            return null;
        }

        $publicUrl = (string) config('filesystems.disks.r2.url');
        $isAlreadyR2 = str_contains($imageUrl, 'r2.dev')
            || (filled($publicUrl) && str_starts_with($imageUrl, $publicUrl));

        if ($isAlreadyR2) {
            return $imageUrl;
        }

        try {
            $response = Http::timeout(15)->get($imageUrl);

            if ($response->failed()) {
                Log::warning('ImageUploader: download failed.', [
                    'url' => $imageUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $source = $response->body();
            $filename = Str::limit(Str::slug($seed), 120, '');

            $contents = self::convertToWebP($source) ?: $source;

            Storage::disk('r2')->put("products/{$filename}.webp", $contents, 'public');

            $r2Url = rtrim($publicUrl, '/')."/products/{$filename}.webp";

            Log::info('ImageUploader: uploaded image to R2.', [
                'url' => $imageUrl,
                'r2_url' => $r2Url,
            ]);

            return $r2Url;
        } catch (\Throwable $e) {
            Log::error('ImageUploader: upload threw exception.', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convert raw image bytes to a WebP-encoded string when the GD extension is
     * available and the source can be decoded. Returns null otherwise.
     *
     * Oversized payloads, un-decodable sources and GD failures are logged with
     * context and never propagated to the caller; the image resource is always
     * freed via imagedestroy() in every code path.
     */
    protected static function convertToWebP(string $source): ?string
    {
        if (! extension_loaded('gd')) {
            Log::warning('ImageUploader: GD extension not loaded; skipping WebP conversion.');

            return null;
        }

        $sizeInBytes = strlen($source);

        if ($sizeInBytes > self::MAX_SOURCE_BYTES) {
            Log::warning('ImageUploader: source exceeds 8MB; skipping WebP conversion.', [
                'size_bytes' => $sizeInBytes,
            ]);

            return null;
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            Log::warning('ImageUploader: GD failed to decode the source image.', [
                'size_bytes' => $sizeInBytes,
            ]);

            return null;
        }

        try {
            ob_start();
            imagewebp($image, null, self::WEBP_QUALITY);
            $webp = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            Log::error('ImageUploader: WebP conversion threw exception.', [
                'error' => $e->getMessage(),
                'size_bytes' => $sizeInBytes,
            ]);

            return null;
        } finally {
            imagedestroy($image);
        }

        if ($webp === false || $webp === '') {
            Log::warning('ImageUploader: WebP conversion produced empty output.', [
                'size_bytes' => $sizeInBytes,
            ]);

            return null;
        }

        return $webp;
    }

    /**
     * Build a stable, cache-friendly filename from the product ASIN + a short hash.
     */
    protected static function buildFilename(Product $product, string $imageUrl): string
    {
        $seed = ($product->asin ?: $product->id).'-'.Str::slug($product->title).'-'.substr(md5($imageUrl), 0, 8);

        return Str::limit(Str::slug($seed), 120, '');
    }
}

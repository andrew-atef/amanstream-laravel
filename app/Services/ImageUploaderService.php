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
     * Minimum canvas dimension for small source images. Images narrower or
     * shorter than this are centered on a white canvas first, so the brand
     * watermark always has room to be visible and the result keeps the clean
     * white look Amazon thumbnails are used to.
     */
    protected const MIN_CANVAS_SIZE = 400;

    /**
     * Download an arbitrary external image URL, apply the AmanPrice brand
     * watermark, upload the resulting WebP to Cloudflare R2 and return the
     * permanent public URL. Returns null on any failure (never throws).
     */
    public static function uploadUrlToR2(string $externalUrl, string $identifier): ?string
    {
        return self::upload($externalUrl, $identifier);
    }

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

            Storage::disk('r2')->put(
                "products/{$filename}.webp",
                $contents,
                [
                    'visibility' => 'public',
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]
            );

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
            // Small sources get padded onto a white canvas so the output
            // always has room for the brand logo and keeps a clean look.
            $image = self::padToMinimumCanvas($image);

            self::applyAmanPriceWatermark($image);

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
     * Path to the pre-rendered AmanPrice logo used as the photo watermark.
     *
     * GD cannot rasterize SVG, so this PNG was generated once from
     * public/logo_dark.svg with transparency preserved. The same file is used
     * on the VPS, so no SVG/Imagick dependency is required at runtime.
     */
    protected const WATERMARK_LOGO_PATH = __DIR__.'/../../public/img/logo_dark_watermark.png';

    /**
     * Center a small image onto a white canvas so the final thumbnail keeps a
     * clean, product-photo look and always leaves room for the brand mark.
     *
     * @param  \GdImage  $image
     * @return \GdImage  Padded canvas; the caller must free the returned resource.
     */
    private static function padToMinimumCanvas(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width >= self::MIN_CANVAS_SIZE && $height >= self::MIN_CANVAS_SIZE) {
            return $image;
        }

        $canvasW = max($width, self::MIN_CANVAS_SIZE);
        $canvasH = max($height, self::MIN_CANVAS_SIZE);

        $canvas = imagecreatetruecolor($canvasW, $canvasH);

        // White background matching Amazon's clean product-photo look.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $white);

        // Center the original inside the canvas.
        $offsetX = (int) round(($canvasW - $width) / 2);
        $offsetY = (int) round(($canvasH - $height) / 2);

        imagecopy($canvas, $image, $offsetX, $offsetY, 0, 0, $width, $height);

        imagedestroy($image);

        return $canvas;
    }

    /**
     * Overlay the Aman logo onto the source image, before WebP encoding.
     *
     * The banner is scaled relative to the image and placed in the bottom-right
     * corner with a small margin. Skipped only on truly postage-stamp sources
     * where no strip would be legible.
     *
     * @param  \GdImage  $gd
     */
    private static function applyAmanPriceWatermark(\GdImage $gd): void
    {
        $width = imagesx($gd);
        $height = imagesy($gd);

        // Skip genuinely tiny sources — the strip would be unreadable.
        if ($width < 96 || $height < 48) {
            return;
        }

        $logoPath = self::WATERMARK_LOGO_PATH;

        if (! is_file($logoPath)) {
            Log::warning('ImageUploader: watermark logo file not found.', ['path' => $logoPath]);

            return;
        }

        $logo = @imagecreatefrompng($logoPath);

        if ($logo === false) {
            Log::warning('ImageUploader: failed to decode watermark logo.', ['path' => $logoPath]);

            return;
        }

        try {
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);

            if ($logoW < 1 || $logoH < 1) {
                return;
            }

            // Scale so the banner is readable but never overwhelms the photo:
            // roughly a third of the image height, with a small floor so wide,
            // short thumbnails still get a visible mark.
            $targetW = (int) round(max($height, $width * 0.5) * 0.32);
            $targetW = max(90, min($targetW, 520));
            $targetH = (int) round($targetW * ($logoH / $logoW));
            $targetH = max(24, $targetH);

            // Keep the composition proportional if the image is very wide.
            if ($targetW > (int) round($width * 0.82)) {
                $targetW = (int) round($width * 0.82);
                $targetH = (int) round($targetW * ($logoH / $logoW));
            }

            $marginX = max(10, (int) round($width * 0.02));
            $marginY = max(10, (int) round($height * 0.02));

            $destX = $width - $targetW - $marginX;
            $destY = $height - $targetH - $marginY;

            imagealphablending($gd, true);
            imagesavealpha($gd, true);

            imagecopyresampled(
                $gd,
                $logo,
                $destX,
                $destY,
                0,
                0,
                $targetW,
                $targetH,
                $logoW,
                $logoH
            );
        } finally {
            imagedestroy($logo);
        }
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

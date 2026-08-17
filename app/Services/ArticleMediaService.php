<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * In-content article media pipeline:
 *  - converts uploaded editor images to WebP (quality 85, resized to a max of
 *    1920px wide, preserving aspect ratio) and stores them on Cloudflare R2
 *    under /articles with immutable cache headers;
 *  - extracts all in-article image URLs that live on the R2 origin so callers
 *    can garbage-collect removed/orphaned objects.
 *
 * GD operations are memory-safe: image resources are always freed via
 * imagedestroy() on every path, and any failure is logged and swallowed so a
 * broken upload can never crash the Filament editor.
 */
class ArticleMediaService
{
    /**
     * WebP encoding quality (0-100). 85 is the sweet spot for article content:
     * visually lossless on photos while cutting megabytes off JPEG wire weight.
     */
    protected const WEBP_QUALITY = 85;

    /**
     * Maximum source payload accepted for conversion. GD decodes the whole
     * image into memory, so oversized uploads on a memory-constrained VPS
     * would risk an OOM kill — everything above this is rejected up-front.
     */
    protected const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    /**
     * Wide images (screenshots, infographics) are downscaled to this width so
     * an article never serves a 4000px+ raw capture at full fidelity.
     */
    protected const MAX_WEBP_WIDTH = 1920;

    /**
     * Upload a Filament editor attachment to R2 as a cache-friendly WebP and
     * return its permanent public URL. Falls back to the original bytes +
     * extension when GD/WebP is unavailable, and returns null (never throws)
     * on any failure so the editor keeps working.
     */
    public static function uploadAndOptimize(TemporaryUploadedFile $file, ?Article $article = null): ?string
    {
        try {
            $source = $file->get();
        } catch (\Throwable $e) {
            Log::warning('ArticleMediaService: could not read the temporary upload.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $sizeInBytes = strlen($source);

        if ($sizeInBytes === 0 || $sizeInBytes > self::MAX_SOURCE_BYTES) {
            Log::warning('ArticleMediaService: upload skipped (empty or oversized file).', [
                'size_bytes' => $sizeInBytes,
            ]);

            return null;
        }

        try {
            $webpContents = self::convertToWebP($source);

            $extension = $webpContents !== null ? 'webp' : self::originalExtension($file);
            $path = self::buildPath($article, $extension);

            Storage::disk('r2')->put($path, $webpContents ?? $source, [
                'visibility' => 'public',
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);

            $url = self::r2BaseUrl().'/'.$path;

            Log::info('ArticleMediaService: uploaded article image to R2.', [
                'path' => $path,
                'size_bytes' => $sizeInBytes,
                'converted_to_webp' => $webpContents !== null,
            ]);

            return $url;
        } catch (\Throwable $e) {
            Log::error('ArticleMediaService: upload threw exception.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract every in-article image URL that lives on the R2 origin (either
     * the configured custom domain such as https://media.amanprice.tech or a
     * {bucket}.r2.dev host) and belongs to the /articles prefix. External
     * images are deliberately ignored so foreign CDNs are never touched by GC.
     *
     * @return array<int, string>
     */
    public static function extractR2Images(?string $content): array
    {
        if (blank($content)) {
            return [];
        }

        preg_match_all(self::r2UrlPattern(), $content, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * Delete a single in-article image object from R2. Only URLs hosted on the
     * R2 origin (custom domain or {bucket}.r2.dev) AND under the /articles
     * prefix are actionable; anything else is refused and logged — a garbled
     * URL fed to GC can never touch a foreign host. Returns whether the object
     * was removed.
     */
    public static function deleteFromR2(string $imageUrl): bool
    {
        try {
            $parts = parse_url($imageUrl);
            $host = (string) ($parts['host'] ?? '');
            $path = ltrim((string) ($parts['path'] ?? ''), '/');

            if (! self::isR2Host($host)) {
                Log::warning('ArticleMediaService: refused to delete non-R2 URL.', [
                    'url' => $imageUrl,
                ]);

                return false;
            }

            if ($path === '' || ! str_starts_with($path, 'articles/')) {
                Log::warning('ArticleMediaService: refused to delete non-article R2 path.', [
                    'url' => $imageUrl,
                ]);

                return false;
            }

            $deleted = Storage::disk('r2')->delete($path);

            if ($deleted) {
                Log::info("ArticleMediaService: deleted orphaned image from R2: {$path}");
            }

            return $deleted;
        } catch (\Throwable $e) {
            Log::error('ArticleMediaService: delete threw exception.', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convert raw image bytes to WebP, downscaling wide sources first so the
     * article payload stays lean. Returns null when GD/WebP is unavailable,
     * the source cannot be decoded, or encoding produced no output — the image
     * resource is freed in every path via the finally block.
     */
    protected static function convertToWebP(string $source): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            Log::warning('ArticleMediaService: GD/WebP unavailable; skipping WebP conversion.');

            return null;
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            Log::warning('ArticleMediaService: GD failed to decode the source image.', [
                'size_bytes' => strlen($source),
            ]);

            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > self::MAX_WEBP_WIDTH) {
                $scaled = @imagescale($image, self::MAX_WEBP_WIDTH, -1);

                if ($scaled !== false) {
                    imagedestroy($image);
                    $image = $scaled;
                }
            }

            ob_start();
            imagewebp($image, null, self::WEBP_QUALITY);
            $webp = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            Log::error('ArticleMediaService: WebP conversion threw exception.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            imagedestroy($image);
        }

        if ($webp === false || $webp === '') {
            Log::warning('ArticleMediaService: WebP conversion produced empty output.');

            return null;
        }

        return $webp;
    }

    /**
     * Build the cache-friendly destination path:
     * articles/art-{slug-or-id}-{timestamp}-{random-hash}.{ext}
     */
    protected static function buildPath(?Article $article, string $extension): string
    {
        $seed = Str::slug((string) ($article?->slug ?? $article?->id ?? 'draft')) ?: 'draft';

        return sprintf(
            'articles/art-%s-%s-%s.%s',
            $seed,
            now()->format('YmdHis'),
            bin2hex(random_bytes(4)),
            $extension
        );
    }

    /**
     * Original extension to use when WebP conversion was not possible, so the
     * object still renders correctly in browsers regardless of conversion state.
     */
    protected static function originalExtension(TemporaryUploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif'], true)
            ? $extension
            : 'png';
    }

    /**
     * Regex matching image URLs under /articles on the configured R2 public
     * domain or any {bucket}.r2.dev host.
     */
    protected static function r2UrlPattern(): string
    {
        $publicHost = (string) parse_url(self::r2BaseUrl(), PHP_URL_HOST);

        $hosts = [];

        if (filled($publicHost)) {
            $hosts[] = preg_quote($publicHost, '~');
        }

        $hosts[] = '[\w-]+\.r2\.dev';

        return '~https?://(?:'.implode('|', $hosts).')/articles/[^\s<>"\')]+~iu';
    }

    /**
     * Whether the host belongs to the R2 origin: the configured public custom
     * domain or any {bucket}.r2.dev host.
     */
    protected static function isR2Host(string $host): bool
    {
        $publicHost = (string) parse_url(self::r2BaseUrl(), PHP_URL_HOST);

        if (filled($publicHost) && strcasecmp($host, $publicHost) === 0) {
            return true;
        }

        return preg_match('/[\w-]+\.r2\.dev$/i', $host) === 1;
    }

    /**
     * Base URL of the R2 public origin (custom domain or r2.dev fallback).
     */
    protected static function r2BaseUrl(): string
    {
        return rtrim((string) config('filesystems.disks.r2.url'), '/');
    }
}

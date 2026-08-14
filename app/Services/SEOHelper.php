<?php

namespace App\Services;

/**
 * Central helper for SEO hygiene: canonical URL unification (www/.) and
 * cleaning scraped titles that leak into H1s, OG tags and schema.org.
 */
class SEOHelper
{
    /**
     * Clean a scraped product/article title:
     *  - strips country markers injected by Amazon's global feeds
     *    ("(المملكة العربية السعودية)" etc.) which pollute Egyptian SEO,
     *  - collapses runs of whitespace and trims leading/trailing spaces
     *    (fixes the " Title/H1 bar-spacing" SERP bug).
     */
    public static function cleanTitle(string $title): string
    {
        $title = preg_replace(
            '/\s*\((المملكة العربية السعودية|السعودية|الإمارات|الإمارات العربية المتحدة|الكويت|قطر|البحرين|عُمان|سلطنة عمان)\)/u',
            '',
            $title
        ) ?? $title;

        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * Absolute site URL with a canonical www. host.
     *
     * Local/dev hosts (http://localhost, http://127.0.0.1:*) are left exactly
     * as configured so tinker/tests keep working; only production https hosts
     * are normalized to the https://www. canonical variant — the same host that
     * every canonical link, OG tag and JSON-LD @id must agree on.
     */
    public static function url(string $path = ''): string
    {
        $base = (string) config('app.url', 'https://www.amanprice.tech');

        if (str_starts_with($base, 'http://')) {
            $baseUrl = rtrim($base, '/');
        } else {
            $host = preg_replace('#^https?://#', '', rtrim($base, '/')) ?? rtrim($base, '/');
            $baseUrl = str_starts_with($host, 'www.') ? 'https://'.$host : 'https://www.'.$host;
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * Canonical link value for the given path.
     */
    public static function canonical(string $path = ''): string
    {
        return self::url($path);
    }
}
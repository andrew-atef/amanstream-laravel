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
     * Render evergreen year tokens as the current calendar year on the fly.
     *
     * Editorial titles/descriptions can embed `[year]`, `%%year%%` or `{year}`
     * so "أفضل تكييفات [year]" automatically becomes "...2026" in January
     * without the author re-editing every article. The raw database content is
     * NEVER mutated — only the read-time value carries the current year.
     */
    public static function renderDynamicYear(?string $text): string
    {
        if (blank($text)) {
            return '';
        }

        $currentYear = date('Y');

        return str_replace(['[year]', '%%year%%', '{year}'], $currentYear, (string) $text);
    }

    /**
     * Absolute site URL with a canonical www. host.
     *
     * Local/dev hosts (http://localhost, http://127.0.0.1:*) are left exactly
     * as configured so tinker/tests keep working; only production https hosts
     * are normalized to the https://www. canonical variant — the same host that
     * every canonical link, OG tag and JSON-LD @id must agree on.
     *
     * A path is joined with exactly one slash (`favicon.svg`, `?q=...` or a
     * multi-segment `category/air-conditioners`), and an empty path yields the
     * bare canonical host. Callers must NOT pre-pend a `/` to the result.
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

        // Leading slashes (or stray "//") are normalized to a single join at
        // the end, so even a caller passing "//favicon.svg" never duplicates.
        $path = (string) $path;
        $path = ltrim($path, '/');

        if ($path === '') {
            return $baseUrl;
        }

        // Collapse any remaining run of slashes inside the path (and the classic
        // "//?q=" residue) so a trailing "//" can never be emitted after the host.
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        return $baseUrl.'/'.$path;
    }

    /**
     * Canonical link value for the given path.
     */
    public static function canonical(string $path = ''): string
    {
        return self::url($path);
    }

    /**
     * Normalize a raw scraped/affiliate URL into a clean canonical Amazon Egypt
     * purchase link. The single source of truth behind every CTA rendered in
     * HTML, Markdown, MCP output and schema.org offers:
     *
     *  - When an ASIN is known, the canonical `https://www.amazon.eg/dp/{ASIN}
     *    ?tag={tag}` is built directly, dropping ANY scraped tracking junk
     *    (`&dib=`, `&crid=`, `&sprefix=`, `&qid=`, `psc`, ...).
     *  - Otherwise the 10-character ASIN is extracted from a messy `/dp/` or
     *    `/gp/product/` URL and the same canonical link is rebuilt.
     *  - Noon links are kept clean and platform-safe (query string stripped).
     *  - Anything else (or an empty input) is returned unchanged.
     */
    public static function cleanAffiliateUrl(?string $url, ?string $asin = null): string
    {
        $tag = config('services.amazon.tag', 'khatfadeals2-21');

        // ASIN is known — build the clean canonical link directly.
        if (filled($asin)) {
            return 'https://www.amazon.eg/dp/'.strtoupper(trim($asin)).'?tag='.$tag;
        }

        if (blank($url)) {
            return '';
        }

        // Extract the 10-character Amazon ASIN from any messy URL string.
        if (preg_match('/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/i', $url, $matches)) {
            return 'https://www.amazon.eg/dp/'.strtoupper($matches[1]).'?tag='.$tag;
        }

        // Keep Noon URLs clean if platform is noon.
        if (str_contains($url, 'noon.com')) {
            return explode('?', $url)[0];
        }

        return $url;
    }
}

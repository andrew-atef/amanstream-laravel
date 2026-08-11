<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareCacheService
{
    /**
     * Purge a single URL from the Cloudflare edge cache.
     *
     * Returns true on success, false when the token/zone are not configured or the request fails.
     */
    public function purgeUrl(string $url): bool
    {
        return $this->purgeUrls([$url]);
    }

    /**
     * Maximum URLs accepted per /purge_cache request (Cloudflare API limit - 300).
     * Chunking stays safely below the cap while balancing request count.
     */
    protected const MAX_URLS_PER_CHUNK = 250;

    /**
     * Query parameter that marks the Markdown cache variant. A zone-level
     * Cloudflare Transform Rule rewrites `Accept: text/markdown` requests to
     * this key so the Markdown representation caches separately from HTML,
     * keeping both URLs purgeable with the regular purge-by-URL mechanism.
     */
    public const MARKDOWN_VARIANT_QUERY = '_fmt=md';

    /**
     * Purge multiple URLs from the Cloudflare edge cache.
     *
     * URLs are deduplicated and cleaned, then split into chunks of at most
     * MAX_URLS_PER_CHUNK and sent as separate requests, because Cloudflare
     * rejects /purge_cache payloads with more than 300 files.
     *
     * Returns true only when every chunk was purged successfully.
     */
    public function purgeUrls(array $urls): bool
    {
        $token = config('services.cloudflare.api_token') ?? config('services.cloudflare.token');
        $zone = config('services.cloudflare.zone_id');

        if (blank($token) || blank($zone)) {
            Log::info('CloudflareCache: token or zone not configured, skipping purge.', [
                'urls' => $urls,
            ]);

            return false;
        }

        $cleanUrls = $this->withMarkdownVariants($this->cleanUrls($urls));

        if ($cleanUrls === []) {
            return true;
        }

        $allPurged = true;

        foreach (array_chunk($cleanUrls, self::MAX_URLS_PER_CHUNK) as $chunk) {
            if (! $this->purgeChunk($chunk)) {
                $allPurged = false;
            }
        }

        return $allPurged;
    }

    /**
     * Deduplicate, trim and drop blank entries from the given URL list.
     *
     * @param  array<int, mixed>  $urls
     * @return array<int, string>
     */
    protected function cleanUrls(array $urls): array
    {
        $cleaned = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $cleaned[$url] = true;
        }

        return array_keys($cleaned);
    }

    /**
     * Expand each URL with its Markdown cache variant so a single purge also
     * clears the `Accept: text/markdown` representation stored under the
     * `?_fmt=md` key created by the zone's Transform Rule.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    public function withMarkdownVariants(array $urls): array
    {
        $expanded = [];

        foreach ($urls as $url) {
            $expanded[] = $url;

            if (str_contains($url, '?'.self::MARKDOWN_VARIANT_QUERY) || str_contains($url, '&'.self::MARKDOWN_VARIANT_QUERY)) {
                continue;
            }

            $separator = str_contains($url, '?') ? '&' : '?';

            $expanded[] = $url.$separator.self::MARKDOWN_VARIANT_QUERY;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Send a single purged request for the given chunk of URLs.
     */
    protected function purgeChunk(array $chunk): bool
    {
        $token = config('services.cloudflare.api_token') ?? config('services.cloudflare.token');
        $zone = config('services.cloudflare.zone_id');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post(
                    'https://api.cloudflare.com/client/v4/zones/'.$zone.'/purge_cache',
                    ['files' => $chunk]
                );

            if ($response->successful()) {
                return true;
            }

            Log::warning('CloudflareCache: purge chunk failed.', [
                'urls' => $chunk,
                'status' => $response->status(),
                'body' => (string) $response->body(),
            ]);

            return false;
        } catch (ConnectionException|\Throwable $e) {
            Log::error('CloudflareCache: purge chunk threw exception.', [
                'urls' => $chunk,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

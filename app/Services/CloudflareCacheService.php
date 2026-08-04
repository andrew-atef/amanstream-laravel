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
     * Purge multiple URLs from the Cloudflare edge cache in a single request.
     *
     * Returns true when every URL was purged successfully, false otherwise.
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

        if ($urls === []) {
            return true;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post(
                    'https://api.cloudflare.com/client/v4/zones/'.$zone.'/purge_cache',
                    ['files' => $urls]
                );

            $ok = $response->successful();

            if (! $ok) {
                Log::warning('CloudflareCache: purge request failed.', [
                    'urls' => $urls,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }

            return $ok;
        } catch (ConnectionException|\Throwable $e) {
            Log::error('CloudflareCache: purge threw exception.', [
                'urls' => $urls,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

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
        $token = config('services.cloudflare.token');
        $zone = config('services.cloudflare.zone_id');

        if (blank($token) || blank($zone)) {
            Log::info('CloudflareCache: token or zone not configured, skipping purge.', ['url' => $url]);

            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post(
                    rtrim(config('services.cloudflare.base_uri'), '/').'/zones/'.$zone.'/purge_cache',
                    ['files' => [$url]]
                );

            $ok = $response->successful();

            if (! $ok) {
                Log::warning('CloudflareCache: purge request failed.', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }

            return $ok;
        } catch (ConnectionException|\Throwable $e) {
            Log::error('CloudflareCache: purge threw exception.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstantIndexingService
{
    /**
     * Google Indexing API v3 notification endpoint.
     */
    protected const GOOGLE_PUBLISH_URL = '/v3/urlNotifications:publish';

    /**
     * Scope required by the Google Indexing API.
     */
    protected const GOOGLE_INDEXING_SCOPE = 'https://www.googleapis.com/auth/indexing';

    /**
     * Notify Google's Indexing API for the given URL.
     *
     * JWT/OAuth2 (RS256) is assembled manually with OpenSSL to avoid an extra dependency.
     * Returns true on success, false when credentials are missing or the request fails.
     */
    public function notifyGoogle(string $url): bool
    {
        try {
            if (! filter_var(config('services.google_indexing.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('InstantIndexing: Google indexing paused by config (GOOGLE_INDEXING_ENABLED=false).', ['url' => $url]);

                return false;
            }

            $credentialsPath = config('services.google_indexing.credentials_path');

            if (blank($credentialsPath) || ! is_file($credentialsPath)) {
                Log::info('InstantIndexing: Google credentials not configured, skipping.', ['url' => $url]);

                return false;
            }

            $accessToken = $this->obtainGoogleAccessToken($credentialsPath);

            if (blank($accessToken)) {
                Log::warning('InstantIndexing: Could not obtain Google access token.', ['url' => $url]);

                return false;
            }

            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post(
                    rtrim(config('services.google_indexing.base_uri'), '/').self::GOOGLE_PUBLISH_URL,
                    [
                        'url' => $url,
                        'type' => 'URL_UPDATED',
                    ]
                );

            $ok = $response->successful();

            if (! $ok) {
                Log::warning('InstantIndexing: Google notification failed.', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }

            return $ok;
        } catch (ConnectionException|\Throwable $e) {
            Log::error('InstantIndexing: Google notification threw exception.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send an IndexNow submission to Bing/IndexNow for the given URL.
     */
    public function notifyIndexNow(string $url): bool
    {
        try {
            if (! filter_var(config('services.indexnow.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('InstantIndexing: IndexNow paused by config (INDEXNOW_ENABLED=false).', ['url' => $url]);

                return false;
            }

            $key = config('services.indexnow.key');

            if (blank($key) || app()->environment('local')) {
                Log::info('InstantIndexing: IndexNow key missing or local environment, skipping.', ['url' => $url]);

                return false;
            }

            $host = (string) parse_url($url, PHP_URL_HOST);

            $payload = [
                'host' => $host,
                'key' => $key,
                'urlList' => [$url],
            ];

            $keyLocation = config('services.indexnow.key_location');

            if (filled($keyLocation)) {
                $payload['keyLocation'] = $keyLocation;
            }

            $response = Http::timeout(15)
                ->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES), 'application/json; charset=utf-8')
                ->post(config('services.indexnow.base_uri'));

            $ok = $response->successful();

            if (! $ok) {
                Log::warning('InstantIndexing: IndexNow notification failed.', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }

            return $ok;
        } catch (ConnectionException|\Throwable $e) {
            Log::error('InstantIndexing: IndexNow notification threw exception.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Exchange the service-account credentials for an OAuth2 access token.
     */
    protected function obtainGoogleAccessToken(string $credentialsPath): ?string
    {
        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || blank($credentials['client_email'] ?? null) || blank($credentials['private_key'] ?? null)) {
            Log::warning('InstantIndexing: Invalid Google service account credentials file.');

            return null;
        }

        $now = time();

        $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::GOOGLE_INDEXING_SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = $this->signJwt("$jwtHeader.$claims", $credentials['private_key']);

        if ($signature === null) {
            return null;
        }

        $jwt = "$jwtHeader.$claims.$signature";

        $response = Http::timeout(15)
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        $data = $response->json();

        if (! $response->successful() || blank($data['access_token'] ?? null)) {
            Log::warning('InstantIndexing: Google token exchange failed.', ['body' => (string) $response->body()]);

            return null;
        }

        return $data['access_token'];
    }

    /**
     * Sign the JWT signing-input with the service account's RS256 private key.
     */
    protected function signJwt(string $signingInput, string $privateKey): ?string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            Log::warning('InstantIndexing: Could not parse Google private key.');

            return null;
        }

        $success = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        return $success ? $this->base64UrlEncode($signature) : null;
    }

    /**
     * Base64URL encoding (without padding), as required by JWT.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleSearchAnalytic;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSearchConsoleService
{
    /**
     * OAuth2 scope for Google Search Console read-only access.
     */
    protected const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Google Search Console API base URL.
     */
    protected const GSC_BASE_URI = 'https://www.googleapis.com/webmasters/v3';

    /**
     * Fetch 30-day organic search analytics for all pages in the property.
     *
     * Returns a Collection keyed by clean URL path (e.g. "/articles/slug")
     * with values: ['clicks' => int, 'impressions' => int, 'ctr' => float, 'position' => float].
     * Returns an empty Collection on any failure — never throws.
     */
    public function fetch30DaySearchAnalytics(): Collection
    {
        try {
            if (! filter_var(config('services.google_search_console.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('GSC: Search Console sync disabled by config.');

                return collect();
            }

            $credentialsPath = config('services.google_search_console.credentials_path');

            if (blank($credentialsPath) || ! is_file($credentialsPath)) {
                Log::info('GSC: Service account credentials not configured, skipping.');

                return collect();
            }

            $accessToken = $this->obtainAccessToken($credentialsPath);

            if (blank($accessToken)) {
                Log::warning('GSC: Could not obtain Google access token.');

                return collect();
            }

            $siteUrl = config('services.google_search_console.site_url', 'https://www.amanprice.tech/');

            $endDate = Carbon::now()->subDays(2)->format('Y-m-d');
            $startDate = Carbon::now()->subDays(32)->format('Y-m-d');

            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page'],
                'rowLimit' => 1000,
            ];

            $encodedSiteUrl = rawurlencode(rtrim($siteUrl, '/'));

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post(
                    self::GSC_BASE_URI.'/sites/'.$encodedSiteUrl.'/searchAnalytics/query',
                    $payload
                );

            if (! $response->successful()) {
                Log::warning('GSC: API request failed.', [
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);

                return collect();
            }

            $data = $response->json('rows', []);

            $results = collect();

            foreach ($data as $row) {
                $pageUrl = $row['keys'][0] ?? null;

                if (blank($pageUrl)) {
                    continue;
                }

                $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';

                $results->put($path, [
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2),
                    'position' => round((float) ($row['position'] ?? 0), 1),
                ]);
            }

            return $results;

        } catch (ConnectionException|\Throwable $e) {
            Log::error('GSC: Failed to fetch search analytics.', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch daily search analytics from GSC and upsert into article_search_analytics.
     *
     * Queries dimensions ["page", "date"] with dataState "all" so both
     * finalized and fresh (partial) data is captured. Rows are upserted
     * per (page_url, date) unique key and linked to published articles by slug.
     *
     * Returns the number of rows upserted.
     */
    public function syncHistoricalSearchAnalytics(int $daysBack = 90): int
    {
        try {
            if (! filter_var(config('services.google_search_console.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('GSC: Search Console sync disabled by config.');

                return 0;
            }

            $credentialsPath = config('services.google_search_console.credentials_path');

            if (blank($credentialsPath) || ! is_file($credentialsPath)) {
                Log::info('GSC: Service account credentials not configured, skipping.');

                return 0;
            }

            $accessToken = $this->obtainAccessToken($credentialsPath);

            if (blank($accessToken)) {
                Log::warning('GSC: Could not obtain Google access token for historical sync.');

                return 0;
            }

            $siteUrl = config('services.google_search_console.site_url', 'https://www.amanprice.tech/');
            $encodedSiteUrl = rawurlencode(rtrim($siteUrl, '/'));

            // GSC API endDate must be at least 1 day before today for fresh data
            $endDate = Carbon::now()->subDays(1)->format('Y-m-d');
            $startDate = Carbon::now()->subDays($daysBack)->format('Y-m-d');

            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page', 'date'],
                'dataState' => 'all',
                'rowLimit' => 25000,
            ];

            $response = Http::withToken($accessToken)
                ->timeout(60)
                ->post(
                    self::GSC_BASE_URI.'/sites/'.$encodedSiteUrl.'/searchAnalytics/query',
                    $payload
                );

            if (! $response->successful()) {
                Log::warning('GSC: Historical analytics API request failed.', [
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);

                return 0;
            }

            $data = $response->json('rows', []);

            if ($data === []) {
                Log::info('GSC: No historical rows returned.');

                return 0;
            }

            // Build a slug→article map for published articles
            $slugMap = Article::query()
                ->where('is_published', true)
                ->pluck('id', 'slug')
                ->all();

            $upserted = 0;
            $batchSize = 500;
            $batch = [];

            foreach ($data as $row) {
                $pageUrl = $row['keys'][0] ?? null;
                $dateStr = $row['keys'][1] ?? null;

                if (blank($pageUrl) || blank($dateStr)) {
                    continue;
                }

                $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
                $cleanUrl = rtrim($path, '/');

                // Extract slug from /articles/{slug} or /blog/{slug}
                $slug = null;
                if (preg_match('#/(?:articles|blog)/([^/]+)$#', $cleanUrl, $m)) {
                    $slug = $m[1];
                }

                $articleId = $slug !== null ? ($slugMap[$slug] ?? null) : null;

                $ctr = round((float) ($row['ctr'] ?? 0) * 100, 2);
                $position = round((float) ($row['position'] ?? 0), 1);

                $batch[] = [
                    'article_id' => $articleId,
                    'page_url' => $cleanUrl,
                    'date' => $dateStr,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => $ctr,
                    'position' => $position,
                ];

                if (count($batch) >= $batchSize) {
                    $upserted += $this->upsertBatch($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $upserted += $this->upsertBatch($batch);
            }

            Log::info("GSC: Historical sync complete — {$upserted} rows upserted.", [
                'start' => $startDate,
                'end' => $endDate,
            ]);

            return $upserted;

        } catch (ConnectionException|\Throwable $e) {
            Log::error('GSC: Historical sync failed.', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Upsert a batch of daily analytics rows using updateOrCreate per unique key.
     */
    protected function upsertBatch(array $batch): int
    {
        $count = 0;

        foreach ($batch as $row) {
            ArticleSearchAnalytic::updateOrCreate(
                ['page_url' => $row['page_url'], 'date' => $row['date']],
                [
                    'article_id' => $row['article_id'],
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Exchange the service-account credentials for an OAuth2 access token.
     *
     * Uses the same manual RS256 JWT flow as InstantIndexingService
     * to keep RAM usage at zero on a 1 GB VPS (no google/apiclient dependency).
     */
    protected function obtainAccessToken(string $credentialsPath): ?string
    {
        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || blank($credentials['client_email'] ?? null) || blank($credentials['private_key'] ?? null)) {
            Log::warning('GSC: Invalid Google service account credentials file.');

            return null;
        }

        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::GSC_SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = $this->signJwt("$header.$claims", $credentials['private_key']);

        if ($signature === null) {
            return null;
        }

        $jwt = "$header.$claims.$signature";

        $response = Http::timeout(15)
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        $data = $response->json();

        if (! $response->successful() || blank($data['access_token'] ?? null)) {
            Log::warning('GSC: Google token exchange failed.', ['body' => (string) $response->body()]);

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
            Log::warning('GSC: Could not parse Google private key.');

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

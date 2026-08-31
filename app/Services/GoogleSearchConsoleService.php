<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Queries dimensions ["page", "date"] with dataState "finalized" for
     * stable, complete data. Rows are upserted per (page_url, date) unique
     * key and linked to published articles by slug.
     *
     * Returns ['upserted' => int, 'error' => ?string, 'diagnostics' => array].
     */
    public function syncHistoricalSearchAnalytics(int $daysBack = 90): array
    {
        $diagnostics = [];

        try {
            if (! filter_var(config('services.google_search_console.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                return ['upserted' => 0, 'error' => 'GSC sync disabled by GOOGLE_GSC_ENABLED=false', 'diagnostics' => $diagnostics];
            }

            $credentialsPath = config('services.google_search_console.credentials_path');

            if (blank($credentialsPath)) {
                return ['upserted' => 0, 'error' => 'GOOGLE_INDEXING_CREDENTIALS_PATH is not set in .env', 'diagnostics' => $diagnostics];
            }

            if (! is_file($credentialsPath)) {
                return ['upserted' => 0, 'error' => "Credentials file not found: {$credentialsPath}", 'diagnostics' => $diagnostics];
            }

            $diagnostics['credentials_path'] = $credentialsPath;

            $credentials = json_decode(file_get_contents($credentialsPath), true);

            if (! is_array($credentials) || blank($credentials['client_email'] ?? null)) {
                return ['upserted' => 0, 'error' => 'Invalid credentials file — missing client_email', 'diagnostics' => $diagnostics];
            }

            $diagnostics['service_account'] = $credentials['client_email'];

            $accessToken = $this->obtainAccessToken($credentialsPath);

            if (blank($accessToken)) {
                return ['upserted' => 0, 'error' => 'Could not obtain Google access token — check private_key in credentials file', 'diagnostics' => $diagnostics];
            }

            $diagnostics['token_obtained'] = true;

            $siteUrl = config('services.google_search_console.site_url', 'https://www.amanprice.tech/');
            $siteUrl = rtrim($siteUrl, '/');
            $encodedSiteUrl = rawurlencode($siteUrl);

            $endDate = Carbon::now()->subDays(1)->format('Y-m-d');
            $startDate = Carbon::now()->subDays($daysBack)->format('Y-m-d');

            $diagnostics['site_url'] = $siteUrl;
            $diagnostics['encoded_url'] = $encodedSiteUrl;
            $diagnostics['date_range'] = "{$startDate} → {$endDate}";

            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page', 'date'],
                'rowLimit' => 25000,
            ];

            $apiUrl = self::GSC_BASE_URI.'/sites/'.$encodedSiteUrl.'/searchAnalytics/query';
            $diagnostics['api_url'] = $apiUrl;

            $response = Http::withToken($accessToken)
                ->timeout(60)
                ->post($apiUrl, $payload);

            $diagnostics['http_status'] = $response->status();

            if (! $response->successful()) {
                $body = (string) $response->body();
                $diagnostics['response_body'] = mb_substr($body, 0, 500);

                return ['upserted' => 0, 'error' => "GSC API returned HTTP {$response->status()}", 'diagnostics' => $diagnostics];
            }

            $data = $response->json('rows', []);
            $diagnostics['rows_returned'] = count($data);

            if ($data === []) {
                return ['upserted' => 0, 'error' => 'GSC returned 0 rows — service account may not be added as a user in Google Search Console for this property', 'diagnostics' => $diagnostics];
            }

            // Build path→article_id map using actual route paths (no slug guessing)
            $pathMap = [];
            Article::query()
                ->where('is_published', true)
                ->select(['id', 'slug', 'type'])
                ->chunkById(200, function ($articles) use (&$pathMap) {
                    foreach ($articles as $article) {
                        $routeName = $article->type === 'blog' ? 'blog.show' : 'articles.show';
                        $path = parse_url(route($routeName, $article->slug), PHP_URL_PATH) ?: '/';
                        $pathMap[rtrim($path, '/')] = $article->id;
                    }
                });

            $diagnostics['published_articles'] = count($pathMap);

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
                $cleanUrl = rtrim($path, '/') ?: '/';
                $dateOnly = substr($dateStr, 0, 10);

                $articleId = $pathMap[$cleanUrl] ?? null;

                $ctr = round((float) ($row['ctr'] ?? 0) * 100, 2);
                $position = round((float) ($row['position'] ?? 0), 1);

                $batch[] = [
                    'article_id' => $articleId,
                    'page_url' => $cleanUrl,
                    'date' => $dateOnly,
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

            $diagnostics['upserted'] = $upserted;

            return ['upserted' => $upserted, 'error' => null, 'diagnostics' => $diagnostics];

        } catch (ConnectionException|\Throwable $e) {
            $diagnostics['exception'] = $e->getMessage();

            return ['upserted' => 0, 'error' => 'Exception: '.$e->getMessage(), 'diagnostics' => $diagnostics];
        }
    }

    /**
     * Fetch daily per-query analytics (page + query + date) and upsert into article_query_analytics.
     *
     * One row per (page_url, query, date). Uses same path→article_id mapping as page analytics.
     *
     * @return array{upserted: int, error: ?string, diagnostics: array}
     */
    public function syncQueryAnalytics(int $daysBack = 90): array
    {
        $diagnostics = [];

        try {
            if (! filter_var(config('services.google_search_console.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                return ['upserted' => 0, 'error' => 'GSC sync disabled', 'diagnostics' => $diagnostics];
            }

            $credentialsPath = config('services.google_search_console.credentials_path');
            if (blank($credentialsPath) || ! is_file($credentialsPath)) {
                return ['upserted' => 0, 'error' => "Credentials file not found: {$credentialsPath}", 'diagnostics' => $diagnostics];
            }

            $credentials = json_decode(file_get_contents($credentialsPath), true);
            if (! is_array($credentials) || blank($credentials['client_email'] ?? null)) {
                return ['upserted' => 0, 'error' => 'Invalid credentials file', 'diagnostics' => $diagnostics];
            }

            $accessToken = $this->obtainAccessToken($credentialsPath);
            if (blank($accessToken)) {
                return ['upserted' => 0, 'error' => 'Could not obtain Google access token', 'diagnostics' => $diagnostics];
            }

            $siteUrl = rtrim(config('services.google_search_console.site_url', 'https://www.amanprice.tech/'), '/');
            $encodedSiteUrl = rawurlencode($siteUrl);
            $endDate = Carbon::now()->subDays(1)->format('Y-m-d');
            $startDate = Carbon::now()->subDays($daysBack)->format('Y-m-d');

            $diagnostics['site_url'] = $siteUrl;
            $diagnostics['date_range'] = "{$startDate} → {$endDate}";

            // Build path→article_id map
            $pathMap = [];
            Article::query()->where('is_published', true)->select(['id', 'slug', 'type'])
                ->chunkById(200, function ($articles) use (&$pathMap) {
                    foreach ($articles as $article) {
                        $routeName = $article->type === 'blog' ? 'blog.show' : 'articles.show';
                        $path = parse_url(route($routeName, $article->slug), PHP_URL_PATH) ?: '/';
                        $pathMap[rtrim($path, '/')] = $article->id;
                    }
                });

            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page', 'query', 'date'],
                'rowLimit' => 25000,
            ];

            $apiUrl = self::GSC_BASE_URI.'/sites/'.$encodedSiteUrl.'/searchAnalytics/query';
            $response = Http::withToken($accessToken)->timeout(60)->post($apiUrl, $payload);
            $diagnostics['http_status'] = $response->status();

            if (! $response->successful()) {
                return ['upserted' => 0, 'error' => "GSC API returned HTTP {$response->status()}", 'diagnostics' => $diagnostics];
            }

            $data = $response->json('rows', []);
            $diagnostics['rows_returned'] = count($data);

            if ($data === []) {
                return ['upserted' => 0, 'error' => 'GSC returned 0 rows for query dimension', 'diagnostics' => $diagnostics];
            }

            $diagnostics['published_articles'] = count($pathMap);
            $upserted = 0;
            $batch = [];
            $batchSize = 500;

            foreach ($data as $row) {
                $pageUrl = $row['keys'][0] ?? null;
                $query = $row['keys'][1] ?? null;
                $dateStr = $row['keys'][2] ?? null;
                if (blank($pageUrl) || blank($query) || blank($dateStr)) {
                    continue;
                }
                $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
                $cleanUrl = rtrim($path, '/') ?: '/';
                $dateOnly = substr($dateStr, 0, 10);
                $articleId = $pathMap[$cleanUrl] ?? null;

                $batch[] = [
                    'article_id' => $articleId,
                    'page_url' => $cleanUrl,
                    'query' => mb_substr(trim((string) $query), 0, 500),
                    'date' => $dateOnly,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2),
                    'position' => round((float) ($row['position'] ?? 0), 1),
                ];

                if (count($batch) >= $batchSize) {
                    $upserted += $this->upsertQueryBatch($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $upserted += $this->upsertQueryBatch($batch);
            }

            $diagnostics['upserted'] = $upserted;

            return ['upserted' => $upserted, 'error' => null, 'diagnostics' => $diagnostics];
        } catch (ConnectionException|\Throwable $e) {
            $diagnostics['exception'] = $e->getMessage();

            return ['upserted' => 0, 'error' => 'Exception: '.$e->getMessage(), 'diagnostics' => $diagnostics];
        }
    }

    protected function upsertQueryBatch(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }
        DB::table('article_query_analytics')->upsert(
            $batch,
            ['page_url', 'query', 'date'],
            ['article_id', 'clicks', 'impressions', 'ctr', 'position']
        );

        return count($batch);
    }

    /**
     * Upsert a batch of daily analytics rows using a true SQL UPSERT.
     */
    protected function upsertBatch(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        DB::table('article_search_analytics')->upsert(
            $batch,
            ['page_url', 'date'],
            ['article_id', 'clicks', 'impressions', 'ctr', 'position']
        );

        return count($batch);
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

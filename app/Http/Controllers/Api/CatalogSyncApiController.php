<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogSyncApiController extends Controller
{
    /**
     * Batch size bounds for the pending queue.
     */
    protected const DEFAULT_LIMIT = 8;

    protected const MAX_LIMIT = 20;

    /**
     * Supply a batch of products awaiting ingestion by the scraper.
     */
    public function pendingSync(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $limit = min(max($limit, 1), self::MAX_LIMIT);

        $products = Product::query()
            ->pendingForCatalogSync()
            ->limit($limit)
            ->get(['id', 'asin', 'platform', 'affiliate_url']);

        return response()->json($products->map(fn (Product $product): array => [
            'id' => $product->id,
            'asin_or_sku' => $product->asin,
            'platform' => $product->platform,
            'url' => $product->affiliate_url,
        ]));
    }

    /**
     * Ingest a batch of sync results reported by the scraper.
     */
    public function syncResults(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'results' => ['required', 'array', 'max:200'],
            'results.*.id' => ['required', 'integer'],
            'results.*.live_price' => ['required', 'numeric'],
            'results.*.was_price' => ['nullable', 'numeric'],
            'results.*.in_stock' => ['required', 'boolean'],
            'results.*.rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'results.*.review_count' => ['nullable', 'integer', 'min:0'],
            'results.*.title' => ['nullable', 'string'],
            'results.*.image_url' => ['nullable', 'string'],
            'results.*.sync_status' => ['required', 'in:success,failed'],
            'results.*.error_reason' => ['nullable', 'string'],
        ]);

        $processed = 0;
        $updatedPrices = 0;
        $refreshedArticles = 0;

        DB::transaction(function () use ($payload, &$processed, &$updatedPrices, &$refreshedArticles) {
            foreach ($payload['results'] as $result) {
                $processed++;

                $product = Product::find($result['id']);

                if ($product === null) {
                    continue;
                }

                if ($result['sync_status'] === 'failed') {
                    $this->applyFailure($product, $result['error_reason'] ?? 'Unknown error');

                    continue;
                }

                $priceChanged = $product->hasMaterialPriceChange((float) $result['live_price']);

                $this->applySuccess($product, $result);

                if ($priceChanged) {
                    $updatedPrices++;
                    $refreshedArticles += $this->refreshProductFreshness($product);
                }
            }
        });

        return response()->json([
            'processed' => $processed,
            'price_updates' => $updatedPrices,
            'articles_refreshed' => $refreshedArticles,
        ]);
    }

    /**
     * Persist a successful sync result with safe concurrency semantics.
     */
    protected function applySuccess(Product $product, array $result): void
    {
        $livePrice = round((float) $result['live_price'], 2);

        $update = [
            'price' => $livePrice,
            'in_stock' => (bool) $result['in_stock'],
            'last_synced_at' => now(),
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'sync_attempts' => 0,
            'last_sync_error' => null,
        ];

        // Keep the struck-through original price only when it represents a real discount.
        $wasPrice = isset($result['was_price']) && (float) $result['was_price'] > $livePrice
            ? round((float) $result['was_price'], 2)
            : null;

        if ($wasPrice !== null) {
            $update['original_price'] = $wasPrice;
        }

        // Refresh rating/review signals only when the scraper actually captured them.
        if (filled($result['rating'] ?? null) && (float) $result['rating'] > 0) {
            $update['rating'] = round((float) $result['rating'], 2);
        }

        if (filled($result['review_count'] ?? null) && (int) $result['review_count'] > 0) {
            $update['review_count'] = (int) $result['review_count'];
        }

        if (filled($result['title'] ?? null)) {
            $update['title'] = $result['title'];
        }
        if (filled($result['image_url'] ?? null)) {
            $update['image_url'] = $result['image_url'];
        }

        $product->fill($update);
        $product->save();
    }

    /**
     * Record a failed attempt and escalate to 'failed' once the limit is reached.
     */
    protected function applyFailure(Product $product, string $errorReason): void
    {
        $attempts = (int) $product->sync_attempts + 1;

        $product->update([
            'sync_attempts' => $attempts,
            'last_sync_error' => mb_substr($errorReason, 0, 1000),
            'last_synced_at' => now(),
            'sync_status' => $attempts >= Product::MAX_SYNC_ATTEMPTS
                ? Product::SYNC_STATUS_FAILED
                : Product::SYNC_STATUS_PENDING,
        ]);
    }

    /**
     * On a material price change, bump the product's updated_at and refresh the
     * cache/indexing for every published article. Returns the number of articles refreshed.
     */
    protected function refreshProductFreshness(Product $product): int
    {
        $product->touch();

        $articles = $product->articles()
            ->where('is_published', true)
            ->get();

        if ($articles->isNotEmpty()) {
            $urls = [
                url('/'),
                url('/sitemap.xml'),
            ];

            foreach ($articles as $article) {
                $urls[] = route('articles.show', $article->slug, true);
            }

            PurgeCloudflareCacheJob::dispatch(array_unique($urls));

            Article::whereIn('id', $articles->pluck('id'))
                ->update(['updated_at' => now()]);
        }

        return $articles->count();
    }
}

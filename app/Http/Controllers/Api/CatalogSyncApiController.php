<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeCloudflareCacheJob;
use App\Jobs\UploadProductImageToR2Job;
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
            ->get(['id', 'asin', 'platform', 'affiliate_url', 'raw_reviews_text']);

        // Never let a CDN (Cloudflare) or browser cache this queue snapshot: a
        // stale copy makes workers obsess over the same products forever.
        return response()
            ->json($products->map(fn (Product $product): array => [
                'id' => $product->id,
                'asin_or_sku' => $product->asin,
                'platform' => $product->platform,
                'url' => $product->affiliate_url,
                'scrape_reviews' => blank($product->raw_reviews_text),
            ]))
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Ingest a batch of sync results reported by the scraper.
     */
    public function syncResults(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'results' => ['required', 'array', 'max:200'],
            'results.*.id' => ['required', 'integer'],
            'results.*.live_price' => ['nullable', 'numeric'],
            'results.*.was_price' => ['nullable', 'numeric'],
            'results.*.in_stock' => ['required', 'boolean'],
            'results.*.rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'results.*.review_count' => ['nullable', 'integer', 'min:0'],
            'results.*.title' => ['nullable', 'string'],
            'results.*.image_url' => ['nullable', 'string'],
            'results.*.raw_reviews_text' => ['nullable', 'string'],
            'results.*.sync_status' => ['required', 'in:success,failed'],
            'results.*.error_reason' => ['nullable', 'string'],
        ]);

        $processed = 0;
        $updatedPrices = 0;
        $refreshedArticles = 0;
        $urlsToPurge = [];
        $imagesToUpload = [];

        DB::transaction(function () use ($payload, &$processed, &$updatedPrices, &$refreshedArticles, &$urlsToPurge, &$imagesToUpload) {
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

                // Out-of-stock is a SUCCESS state, not a failure: the product page
                // loaded and is simply not buyable right now. It must still move
                // (re)sync forward so it leaves the pending queue.
                $livePrice = round((float) ($result['live_price'] ?? 0), 2);
                $priceChanged = (bool) $result['in_stock'] && $product->hasMaterialPriceChange($livePrice);

                $this->applySuccess($product, $result, $imagesToUpload);

                if ($priceChanged) {
                    $updatedPrices++;
                    $refreshedArticles += $this->refreshProductFreshness($product, $urlsToPurge);
                }
            }
        });

        if ($urlsToPurge !== []) {
            PurgeCloudflareCacheJob::dispatch(array_values(array_unique($urlsToPurge)));
        }

        foreach ($imagesToUpload as $product) {
            UploadProductImageToR2Job::dispatch($product->id, $product->image_url);
        }

        return response()
            ->json([
                'processed' => $processed,
                'price_updates' => $updatedPrices,
                'articles_refreshed' => $refreshedArticles,
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Persist a successful sync result with safe concurrency semantics.
     *
     * @param  array<int, Product>  $imagesToUpload  Mutated by reference — products with external image URLs are appended here.
     */
    protected function applySuccess(Product $product, array $result, array &$imagesToUpload = []): void
    {
        $livePrice = round((float) ($result['live_price'] ?? 0), 2);
        $previousPrice = (float) $product->price;
        $inStock = (bool) $result['in_stock'];

        // Keep the last known good price when an out-of-stock item reports no
        // live price (so "أفضل سعر سُجِّل" and the badge stay stable).
        $finalPrice = ($inStock === false && $livePrice <= 0)
            ? ($previousPrice > 0 ? $previousPrice : 0)
            : $livePrice;

        $update = [
            'price' => $finalPrice,
            'in_stock' => $inStock,
            'last_synced_at' => now(),
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'sync_attempts' => 0,
            'last_sync_error' => null,
        ];

        // Keep the struck-through original price only when it represents a real discount.
        $wasPrice = isset($result['was_price']) && (float) $result['was_price'] > $finalPrice
            ? round((float) $result['was_price'], 2)
            : null;

        if ($wasPrice !== null) {
            $update['original_price'] = $wasPrice;
        }

        // Refresh rating/review signals, including clearing them to 0 when the
        // scraper confirms the product has no reviews at all (it now scopes
        // strictly to the official #acrPopover / #acrCustomerReviewText widget).
        if (array_key_exists('rating', $result) && $result['rating'] !== null) {
            $update['rating'] = round((float) $result['rating'], 2);
        }

        if (array_key_exists('review_count', $result) && $result['review_count'] !== null) {
            $update['review_count'] = max(0, (int) $result['review_count']);
        }

        if (filled($result['title'] ?? null)) {
            $update['title'] = $result['title'];
        }

        // SMART R2 GUARD: only set and upload the external image if the
        // product does NOT already have an R2 image. Otherwise each sync
        // would overwrite the stored R2 URL with the Amazon URL and re-queue
        // the download → watermark → upload cycle every 6 hours.
        if (filled($result['image_url'] ?? null)) {
            if ($this->isExternalImageUrl((string) $product->image_url)) {
                $update['image_url'] = $result['image_url'];
                $imagesToUpload[] = $product;
            }
        }

        // Persist scraped reviews only when we don't already have them, so the
        // proxy-heavy scrape happens exactly once per product.
        if (blank($product->raw_reviews_text) && filled($result['raw_reviews_text'] ?? null)) {
            $update['raw_reviews_text'] = $result['raw_reviews_text'];
            $update['reviews_scraped_at'] = now();
        }

        // Golden rule: log a history row ONLY when a buyable product's price
        // actually moved. Out-of-stock snapshots must never pollute the range.
        if ($inStock && $finalPrice > 0) {
            $product->recordPriceHistory($finalPrice, now(), $previousPrice);
        }

        $product->fill($update);
        $product->save();
    }

    /**
     * Whether the given image URL lives on an external host and therefore
     * should be downloaded and mirrored to R2.
     */
    protected function isExternalImageUrl(string $imageUrl): bool
    {
        $publicUrl = (string) config('filesystems.disks.r2.url');

        return ! str_contains($imageUrl, 'r2.dev')
            && ! (filled($publicUrl) && str_starts_with($imageUrl, $publicUrl));
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
     * On a material price change, bump the product's updated_at and collect
     * all affected URLs for post-commit cache purge.
     *
     * @param  array<string>  $urlsToPurge  Mutated by reference — URLs are appended here.
     */
    protected function refreshProductFreshness(Product $product, array &$urlsToPurge): int
    {
        $product->touch();

        $articles = $product->articles()
            ->where('is_published', true)
            ->get();

        if ($articles->isNotEmpty()) {
            $urlsToPurge[] = url('/');
            $urlsToPurge[] = url('/sitemap.xml');

            foreach ($articles as $article) {
                $urlsToPurge[] = route('articles.show', $article->slug, true);
            }

            Article::whereIn('id', $articles->pluck('id'))
                ->update(['updated_at' => now()]);
        }

        return $articles->count();
    }
}

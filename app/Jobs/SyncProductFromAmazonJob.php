<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Amazon\AmazonUrlDataFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncProductFromAmazonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 5;

    public function __construct(public readonly int $productId) {}

    /**
     * Pull live marketplace data for a single product from the catalog scraper
     * Worker and persist it, mirroring the semantics of the scraper's batched
     * catalog sync results endpoint so manual and automatic pulls stay consistent.
     */
    public function handle(AmazonUrlDataFetcher $fetcher): void
    {
        $product = Product::find($this->productId);

        if ($product === null) {
            return;
        }

        $url = (string) $product->getRawOriginal('affiliate_url');

        if (blank($url)) {
            $this->markFailed($product, 'لا يوجد رابط أمازون للمنتج');

            return;
        }

        try {
            $data = $fetcher->fetch($url);
        } catch (Throwable $e) {
            $this->markFailed($product, $e->getMessage());

            return;
        }

        $this->applySuccess($product, $data);
    }

    /**
     * Apply the fetched payload onto the product with the same guard-rails as
     * the batched catalog sync results handler: keep the last known good price
     * for out-of-stock items, refresh rating/review signals, mirror external
     * images to R2 asynchronously, and log a history point only on a real move.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applySuccess(Product $product, array $data): void
    {
        $livePrice = round((float) ($data['price'] ?? 0), 2);
        $previousPrice = (float) $product->price;
        $inStock = (bool) ($data['in_stock'] ?? true);

        // An out-of-stock page may report price 0 — treat it as a real SUCCESS
        // state and keep the last known good price so badges/history stay sane.
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
        $wasPrice = ($data['original_price'] ?? null);
        if ($wasPrice !== null && (float) $wasPrice > $finalPrice) {
            $update['original_price'] = round((float) $wasPrice, 2);
        }

        if (filled($data['title'] ?? null)) {
            $update['title'] = $data['title'];
        }

        if (filled($data['brand'] ?? null)) {
            $update['brand'] = $data['brand'];
        }

        // 0 is meaningful: a reviewless product must clear the stale stored signals.
        if (array_key_exists('rating', $data) && $data['rating'] !== null) {
            $update['rating'] = round((float) $data['rating'], 2);
        }

        if (array_key_exists('review_count', $data) && $data['review_count'] !== null) {
            $update['review_count'] = max(0, (int) $data['review_count']);
        }

        // SMART R2 GUARD: only migrate an external image when the product does
        // NOT already own an R2 image, preventing a re-upload cycle on every pull.
        if (filled($data['image_url'] ?? null) && $this->isExternalImageUrl((string) $product->image_url, (string) $data['image_url'])) {
            $update['image_url'] = $data['image_url'];
            UploadProductImageToR2Job::dispatch($product->id, (string) $data['image_url']);
        }

        // Persist scraped reviews only when we don't already have them.
        if (blank($product->raw_reviews_text) && filled($data['raw_reviews_text'] ?? null)) {
            $update['raw_reviews_text'] = $data['raw_reviews_text'];
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
     * Whether the incoming image URL lives on an external host relative to the
     * stored R2 public URL and therefore should be mirrored to R2.
     */
    protected function isExternalImageUrl(string $currentImageUrl, string $incomingImageUrl): bool
    {
        $r2PublicUrl = (string) config('filesystems.disks.r2.url');

        return filled($incomingImageUrl)
            && ! str_contains($incomingImageUrl, 'r2.dev')
            && ! (filled($r2PublicUrl) && str_starts_with($incomingImageUrl, $r2PublicUrl))
            && ! $this->isR2Image($currentImageUrl, $r2PublicUrl);
    }

    /**
     * Whether the given URL is already an R2-hosted image (either via the
     * bucket's r2.dev host or the configured public URL).
     */
    protected function isR2Image(string $imageUrl, string $r2PublicUrl): bool
    {
        if (blank($imageUrl)) {
            return false;
        }

        return str_contains($imageUrl, 'r2.dev')
            || (filled($r2PublicUrl) && str_starts_with($imageUrl, $r2PublicUrl));
    }

    /**
     * Record a failed attempt and escalate to 'failed' once the limit is reached.
     */
    protected function markFailed(Product $product, string $errorReason): void
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
}

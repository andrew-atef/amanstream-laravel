<?php

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Jobs\UploadProductImageToR2Job;
use App\Models\Article;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductObserver
{
    /**
     * Core product attributes whose changes should trigger cache invalidation
     * across all linked article pages.
     *
     * @var array<int, string>
     */
    protected const CORE_ATTRIBUTES = [
        'price',
        'original_price',
        'in_stock',
        'rating',
        'title',
    ];

    /**
     * Dispatch a homepage + sitemap purge when a brand-new product is created.
     * (It will likely not have articles yet, but the homepage listing may change.)
     */
    public function saved(Product $product): void
    {
        if (! $product->wasRecentlyCreated) {
            return;
        }

        PurgeCloudflareCacheJob::dispatch($this->siteUrls());
    }

    /**
     * When a core attribute (price, in_stock, rating, etc.) or the image URL
     * changes on an existing product, mirror any external image to R2, touch
     * every linked published article so that `updated_at` signals freshness
     * to Google, and purge all affected URLs.
     */
    public function updated(Product $product): void
    {
        $imageChanged = $product->wasChanged('image_url')
            && $this->isExternalImageUrl((string) $product->image_url);

        if ($imageChanged) {
            UploadProductImageToR2Job::dispatch($product->id, (string) $product->image_url);
        }

        $this->syncLinkedArticlesCategory($product);

        if (! $this->hasCoreAttributeChanged($product) && ! $imageChanged) {
            return;
        }

        // Touching article `updated_at` changes the sitemap's per-article and
        // per-category `lastmod`, so the 6-hour cached XML must be dropped here
        // too — otherwise Google reads stale freshness hints until expiry.
        Cache::forget('sitemap_xml_content');

        $urls = $this->siteUrls();

        $articles = $product->articles()
            ->where('is_published', true)
            ->get(['id', 'slug']);

        if ($articles->isNotEmpty()) {
            Article::whereIn('id', $articles->pluck('id'))
                ->update(['updated_at' => now()]);
        }

        foreach ($articles as $article) {
            $urls[] = route('articles.show', $article->slug, true);
        }

        PurgeCloudflareCacheJob::dispatch(array_values(array_unique($urls)));
    }

    /**
     * Keep every linked article's category in lockstep with the product.
     * Runs on both create and update; a no-op when the category is unchanged.
     */
    protected function syncLinkedArticlesCategory(Product $product): void
    {
        if (! $product->wasChanged('category_id') || $product->category_id === null) {
            return;
        }

        $product->articles()->where('category_id', '!=', $product->category_id)
            ->update(['category_id' => $product->category_id]);
    }

    /**
     * Homepage (both slash variants) and sitemap URLs that are cached.
     *
     * @return array<string>
     */
    protected function siteUrls(): array
    {
        return [
            url('/'),
            rtrim(url('/'), '/').'/',
            url('/sitemap.xml'),
        ];
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
     * Check whether any of the monitored core attributes were modified
     * during this save cycle.
     */
    protected function hasCoreAttributeChanged(Product $product): bool
    {
        foreach (self::CORE_ATTRIBUTES as $attribute) {
            if ($product->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}

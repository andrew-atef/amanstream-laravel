<?php

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Product;

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

        PurgeCloudflareCacheJob::dispatch([
            url('/'),
            url('/sitemap.xml'),
        ]);
    }

    /**
     * When a core attribute (price, in_stock, rating, etc.) changes on an
     * existing product, touch every linked published article so that
     * `updated_at` signals freshness to Google, and purge all affected URLs.
     */
    public function updated(Product $product): void
    {
        if (! $this->hasCoreAttributeChanged($product)) {
            return;
        }

        $urls = [
            url('/'),
            url('/sitemap.xml'),
        ];

        $articles = $product->articles()
            ->where('is_published', true)
            ->get();

        foreach ($articles as $article) {
            $article->touch();

            $urls[] = route('articles.show', $article->slug, true);
        }

        PurgeCloudflareCacheJob::dispatch(array_unique($urls));
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

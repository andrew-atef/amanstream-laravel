<?php

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Product;
use App\Services\ImageUploaderService;

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
            ImageUploaderService::uploadToR2($product);
        }

        if (! $this->hasCoreAttributeChanged($product) && ! $imageChanged) {
            return;
        }

        $urls = $this->siteUrls();

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

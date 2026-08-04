<?php

namespace App\Services\Amazon;

use App\Models\Product;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;

/**
 * No-op placeholder that keeps the current stored values.
 *
 * Swap this binding in a real production service with an actual
 * Amazon PA-API v5 request (or scraping adapter) that returns the
 * live marketplace payload for each product.
 */
class PlaceholderAmazonProductDataFetcher implements AmazonProductDataFetcher
{
    public function fetch(Product $product): array
    {
        return [
            'price' => (float) $product->price,
            'in_stock' => $product->in_stock,
            'rating' => (float) $product->rating,
            'review_count' => $product->review_count,
        ];
    }
}

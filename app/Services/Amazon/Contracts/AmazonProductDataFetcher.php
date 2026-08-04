<?php

namespace App\Services\Amazon\Contracts;

use App\Models\Product;

interface AmazonProductDataFetcher
{
    /**
     * Resolve the live marketplace data for a product.
     *
     * The returned payload should mirror the shape consumed by the sync command:
     *
     *   [
     *     'price'        => (float) current selling price in EGP,
     *     'in_stock'     => (bool) whether the item is available,
     *     'rating'       => (float) average rating 0..5 (optional),
     *     'review_count' => (int) number of reviews (optional),
     *   ]
     *
     * @return array<string, int|float|bool>
     */
    public function fetch(Product $product): array;
}

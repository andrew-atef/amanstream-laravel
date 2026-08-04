<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a full Amazon product payload from an affiliate URL via the catalog
 * scraper Worker. Used by the "Auto-Fetch" action in the Filament product form.
 */
class AmazonUrlDataFetcher
{
    /**
     * Fetch live product data for the given affiliate URL and map it to the
     * shape expected by the Product model/form.
     *
     * @return array<string, mixed>
     */
    public function fetch(string $affiliateUrl): array
    {
        $uri = config('services.amazon_scraper.base_uri');
        $timeout = (int) config('services.amazon_scraper.timeout', 15);
        $platform = config('services.amazon_scraper.platform', 'amazon');

        $response = Http::timeout($timeout)->get($uri, [
            'url' => $affiliateUrl,
            'platform' => $platform,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Scraper Worker responded with HTTP {$response->status()}.");
        }

        $data = $response->json();

        $price = $data['live_price'] ?? null;
        $wasPrice = $data['was_price'] ?? null;

        return [
            'asin' => $this->extractAsin($affiliateUrl),
            'title' => $data['title'] ?? null,
            'price' => $price,
            'original_price' => ($wasPrice !== null && $price !== null && (float) $wasPrice > (float) $price)
                ? $wasPrice
                : null,
            'image_url' => $data['image_url'] ?? null,
            'rating' => ($data['rating'] ?? 0) > 0 ? $data['rating'] : null,
            'review_count' => ($data['review_count'] ?? 0) > 0 ? $data['review_count'] : null,
            'in_stock' => (bool) ($data['in_stock'] ?? true),
        ];
    }

    /**
     * Extract the 10-char Amazon ASIN from an affiliate URL's dp path segment.
     */
    public function extractAsin(string $url): ?string
    {
        preg_match('/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/i', $url, $matches);

        return isset($matches[1]) ? strtoupper($matches[1]) : null;
    }
}

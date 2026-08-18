<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DeepScrapeDiffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeepScrapeApiController extends Controller
{
    /**
     * How many pending products the worker may pick up per batch.
     */
    protected const DEFAULT_LIMIT = 10;

    public function __construct(protected DeepScrapeDiffService $diffService)
    {
    }

    /**
     * Supply a batch of products awaiting a deep spec scrape by the Playwright worker.
     */
    public function pending(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', self::DEFAULT_LIMIT), 1), self::DEFAULT_LIMIT);

        $products = Product::query()
            ->pendingForDeepScrape()
            ->limit($limit)
            ->get(['id', 'asin', 'affiliate_url']);

        return response()
            ->json($products->map(fn (Product $product): array => [
                'id' => $product->id,
                'asin' => $product->asin,
                'url' => (string) $product->getRawOriginal('affiliate_url'),
            ]))
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Ingest one rich deep-scrape payload, running the semantic diff against
     * the previously stored snapshot so the admin sees exactly what Amazon
     * changed since the last capture.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $product = Product::find($validated['id']);

        if ($product === null) {
            throw ValidationException::withMessages([
                'id' => 'المنتج غير موجود.',
            ]);
        }

        if (strtoupper((string) $product->asin) !== strtoupper((string) $validated['asin'])) {
            return response()
                ->json([
                    'success' => false,
                    'message' => "ASIN mismatch: product #{$product->id} expects {$product->asin}, got {$validated['asin']}.",
                ], 422)
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
                ->header('CDN-Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        }

        $oldPayload = (array) ($product->deep_data_json ?? []);
        $diffs = $this->diffService->diff($oldPayload, $request->all());

        $status = $diffs === []
            ? Product::DEEP_SCRAPE_STATUS_SYNCED
            : Product::DEEP_SCRAPE_STATUS_UPDATED_WITH_DIFF;

        DB::transaction(function () use ($product, $request, $diffs, $status): void {
            $update = [
                'raw_amazon_data' => (string) ($request->input('raw_amazon_data_text') ?? $product->raw_amazon_data ?? ''),
                'deep_data_json' => $request->all(),
                'deep_scraped_at' => now(),
                'deep_scrape_status' => $status,
                'spec_diff_json' => $diffs === [] ? null : $diffs,
            ];

            $pricing = $request->input('pricing');
            $availability = $request->input('availability');

            $livePrice = $this->floatOrNull(is_array($pricing) ? $pricing['live_price'] ?? null : null);
            $listPrice = $this->floatOrNull(is_array($pricing) ? $pricing['list_price'] ?? null : null);

            $inStock = is_array($availability) && array_key_exists('in_stock', $availability)
                ? (bool) $availability['in_stock']
                : $product->in_stock;

            $update['in_stock'] = $inStock;

            // Golden rule from catalog sync: only a BUYABLE item's live price
            // is written through, and only material moves are recorded in the
            // price-history range that powers the "أفضل سعر سُجِّل" badge.
            if ($livePrice !== null && $livePrice > 0 && $inStock) {
                $previousPrice = (float) $product->price;

                $update['price'] = $livePrice;

                if ($listPrice !== null && $listPrice > $livePrice) {
                    $update['original_price'] = $listPrice;
                }

                if ($product->hasMaterialPriceChange($livePrice)) {
                    $product->recordPriceHistory($livePrice, now(), $previousPrice);
                }
            }

            $product->fill($update);
            $product->save();
        });

        return response()
            ->json([
                'success' => true,
                'status' => $status,
                'diff_count' => count($diffs),
            ])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'id' => ['required', 'integer', 'exists:products,id'],
            'asin' => ['required', 'string'],
            'quick_specs' => ['nullable', 'array'],
            'detailed_specifications' => ['nullable', 'array'],
            'warranty_addons' => ['nullable', 'array'],
            'additional_services' => ['nullable', 'array'],
            'about_this_item' => ['nullable', 'array'],
            'product_description' => ['nullable', 'string'],
            'raw_amazon_data_text' => ['nullable', 'string'],
            'pricing' => ['nullable', 'array'],
            'availability' => ['nullable', 'array'],
        ]);
    }

    protected function floatOrNull(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
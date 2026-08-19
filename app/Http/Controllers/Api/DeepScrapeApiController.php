<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DeepScrapeDiffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeepScrapeApiController extends Controller
{
    /**
     * How many pending products the worker may pick up per batch.
     */
    protected const DEFAULT_LIMIT = 10;

    /**
     * Qualitative fields persisted into `deep_specs_json` by the Playwright
     * worker. The workflow NEVER touches the commercial pipeline (price,
     * original_price, in_stock, rating, review_count) — those are owned by the
     * separate daily catalog sync and are dropped before anything is stored.
     */
    private const STORED_FIELDS = [
        'warranty_programs',
        'installation_services',
        'quick_specs',
        'about_this_item',
        'technical_details',
        'manufacturer_content',
        'product_description',
        'customer_reviews_sample',
    ];

    /**
     * Commercial keys that must NEVER survive ingestion. They are whitelisted
     * out of storage by STORED_FIELDS and additionally stripped defensively at
     * the top level so a future code change cannot accidentally widen the
     * storage surface (nested list prices inside warranty/installation rows are
     * intentional qualitative facts and are left untouched).
     */
    private const FORBIDDEN_COMMERCIAL_KEYS = [
        'price',
        'live_price',
        'original_price',
        'in_stock',
        'rating',
        'review_count',
    ];

    public function __construct(protected DeepScrapeDiffService $diffService) {}

    /**
     * Supply a batch of products awaiting a deep editorial spec scrape by the
     * Playwright worker.
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
     * Ingest one rich editorial deep-scrape payload, running the qualitative
     * diff against the previously stored snapshot so the admin copywriter sees
     * exactly what Amazon changed since the last capture.
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
            return $this->noCacheJson([
                'success' => false,
                'message' => "ASIN mismatch: product #{$product->id} expects {$product->asin}, got {$validated['asin']}.",
            ], 422);
        }

        // Whitelist storage to the qualitative fields, then defensively drop
        // any lingering top-level commercial keys. The diff engine and save
        // NEVER see a commercial attribute, so rating/review_count/price/in_stock
        // and the price-history pipeline stay untouched.
        $incoming = $this->stripCommercial($request->only(self::STORED_FIELDS));

        $diffs = $this->diffService->diff($product->deep_specs_json, $incoming);

        $status = $diffs === []
            ? Product::DEEP_SCRAPE_STATUS_SYNCED
            : Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED;

        $product->fill([
            'raw_amazon_data' => (string) $validated['raw_amazon_data_text'],
            'deep_specs_json' => $incoming,
            'spec_diff_json' => $diffs === [] ? null : $diffs,
            'deep_scrape_status' => $status,
            'deep_scraped_at' => now(),
        ]);

        // Quiet save: no observers, no events — this persistence lane is
        // strictly editorial and must never leak into the sync pipeline.
        $product->saveQuietly();

        return $this->noCacheJson([
            'success' => true,
            'status' => $status,
            'diff_count' => count($diffs),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'id' => ['required', 'integer', 'exists:products,id'],
            'asin' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'warranty_programs' => ['nullable', 'array'],
            'installation_services' => ['nullable', 'array'],
            'quick_specs' => ['nullable', 'array'],
            'about_this_item' => ['nullable', 'array'],
            'technical_details' => ['nullable', 'array'],
            'manufacturer_content' => ['nullable', 'string'],
            'product_description' => ['nullable', 'string'],
            'customer_reviews_sample' => ['nullable', 'array'],
            'raw_amazon_data_text' => ['required', 'string'],
        ]);
    }

    /**
     * Drop every top-level commercial key so leftover worker noise can never
     * flow into `deep_specs_json` (the model columns are never touched anyway —
     * this keeps the stored snapshot clean and self-documenting).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripCommercial(array $payload): array
    {
        foreach (self::FORBIDDEN_COMMERCIAL_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function noCacheJson(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->header('CDN-Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }
}

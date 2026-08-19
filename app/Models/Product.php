<?php

namespace App\Models;

use App\Services\SEOHelper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model
{
    public const SYNC_STATUS_PENDING = 'pending';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

    public const DEEP_SCRAPE_STATUS_IDLE = 'idle';

    public const DEEP_SCRAPE_STATUS_PENDING = 'pending';

    public const DEEP_SCRAPE_STATUS_SYNCED = 'synced';

    public const DEEP_SCRAPE_STATUS_SPECS_CHANGED = 'specs_changed';

    public const DEEP_SCRAPE_STATUS_FAILED = 'failed';

    /**
     * Maximum number of failed attempts before a product is flagged as failed.
     */
    public const MAX_SYNC_ATTEMPTS = 5;

    /**
     * How many hours may pass before a synced product automatically returns
     * to the catalog sync queue for a refresh.
     */
    public const SYNC_RECYCLE_HOURS = 6;

    protected $fillable = [
        'category_id',
        'title',
        'asin',
        'brand',
        'price',
        'original_price',
        'lowest_price',
        'highest_price',
        'price_history_json',
        'rating',
        'review_count',
        'affiliate_url',
        'image_url',
        'raw_reviews_text',
        'raw_amazon_data',
        'reviews_scraped_at',
        'in_stock',
        'supports_installment',
        'is_active',
        'platform',
        'sync_status',
        'sync_attempts',
        'last_sync_error',
        'last_synced_at',
        'deep_scrape_status',
        'deep_specs_json',
        'spec_diff_json',
        'deep_scraped_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'lowest_price' => 'decimal:2',
            'highest_price' => 'decimal:2',
            'price_history_json' => 'array',
            'rating' => 'decimal:2',
            'review_count' => 'integer',
            'in_stock' => 'boolean',
            'supports_installment' => 'boolean',
            'is_active' => 'boolean',
            'sync_attempts' => 'integer',
            'last_sync_error' => 'string',
            'last_synced_at' => 'datetime',
            'reviews_scraped_at' => 'datetime',
            'deep_scrape_status' => 'string',
            'deep_specs_json' => 'array',
            'spec_diff_json' => 'array',
            'deep_scraped_at' => 'datetime',
        ];
    }

    /**
     * Title is always cleaned on read (single source of truth), so legacy
     * rows scraped with "(المملكة العربية السعودية)" style pollution never
     * leak into H1s, OG tags, JSON-LD or component cards. Writes are cleaned
     * by the saving hook below.
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : SEOHelper::cleanTitle($value),
        );
    }

    /**
     * Affiliate/purchase URLs are ALWAYS normalized on read to the clean
     * canonical Amazon Egypt link (see SEOHelper::cleanAffiliateUrl), so no
     * raw scraped tracking junk (`&dib=`, `&crid=`, `&sprefix=`, `&qid=` ...)
     * can ever leak into any Blade component, shortcode, Markdown variant,
     * schema.org JSON-LD or MCP output.
     *
     * Noon platform links are preserved as clean platform URLs (query string
     * stripped) — never rebuilt as Amazon links from their SKU string.
     */
    protected function affiliateUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => SEOHelper::cleanAffiliateUrl(
                $value,
                $value !== null && str_contains($value, 'noon.com') ? null : (string) $this->asin
            ),
        );
    }

    /**
     * Explicit `clean_affiliate_url` accessor (single source of truth so any
     * Blade/view can use `$product->clean_affiliate_url` without relying on
     * the shorthand `affiliate_url` attribute override).
     */
    public function getCleanAffiliateUrlAttribute(): string
    {
        $raw = (string) ($this->attributes['affiliate_url'] ?? '');
        $asin = str_contains($raw, 'noon.com') ? null : (string) ($this->attributes['asin'] ?? '');

        return SEOHelper::cleanAffiliateUrl($raw, $asin);
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->asin = trim(strtoupper((string) $product->asin));

            // Scraped-title hygiene: strip foreign country markers and normalize
            // whitespace so titles captured from Amazon's global feeds never leak
            // "(المملكة العربية السعودية)" or stray leading spaces into H1/OG/schema.
            if ($product->title !== null) {
                $product->title = SEOHelper::cleanTitle((string) $product->title);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)
            ->orderBy('recorded_at');
    }

    /**
     * Products awaiting ingestion by the catalog scraper.
     *
     * The 6-hour cron (catalog:reset-sync-queue) is the single source of truth
     * for requeueing: it flips synced/failed products back to pending. So this
     * scope serves ONLY active products whose status is pending — no extra
     * NULL/stale fallbacks (they'd duplicate the cron's job and pin the same
     * stuck items at the front forever). Never-synced first (NULLS FIRST),
     * then oldest-to-newest.
     */
    public function scopePendingForCatalogSync($query)
    {
        return $query
            ->where('is_active', true)
            ->where('sync_status', self::SYNC_STATUS_PENDING)
            ->orderByRaw('last_synced_at IS NULL DESC')
            ->orderBy('last_synced_at');
    }

    /**
     * Active products awaiting a full deep scrape by the Playwright worker.
     */
    public function scopePendingForDeepScrape($query)
    {
        return $query
            ->where('is_active', true)
            ->where('deep_scrape_status', self::DEEP_SCRAPE_STATUS_PENDING);
    }

    /**
     * Whether the given live price represents a material change (>3%) vs the stored price.
     */
    public function hasMaterialPriceChange(float $livePrice, float $threshold = 0.03): bool
    {
        $current = (float) $this->price;

        if ($current <= 0) {
            return $livePrice > 0;
        }

        return abs($livePrice - $current) / $current > $threshold;
    }

    /**
     * Plans that are eligible for this product based on its current price.
     *
     * @return Collection<int, InstallmentPlan>
     */
    public function getEligibleInstallmentPlans()
    {
        if ($this->supports_installment === false) {
            return collect();
        }

        return InstallmentPlan::query()
            ->with('bank')
            ->where('min_order_amount', '<=', $this->price)
            ->whereHas('bank', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_zero_interest')
            ->orderBy('months')
            ->get();
    }

    /**
     * Log a price snapshot ONLY when the price actually changed on a buyable item.
     * Maintains ready-made lowest_price, highest_price, and compact price_history_json.
     */
    public function recordPriceHistory(float $livePrice, ?CarbonInterface $when = null, ?float $previousPrice = null): void
    {
        // A zero or negative snapshot is never a real sellable price. Callers
        // already guard on that, but keep the model self-defending so a 0.00
        // can never permanently floor the recorded range.
        if ($livePrice <= 0) {
            return;
        }

        $when ??= now();
        $previousPrice ??= (float) $this->price;
        $points = (array) ($this->price_history_json ?? []);

        // If first time recording, push previous price snapshot if valid
        if (empty($points) && $previousPrice > 0 && abs($previousPrice - $livePrice) >= 0.01) {
            $points[] = ['p' => $previousPrice, 'd' => $when->subDay()->format('d/m')];
        }

        // Ignore micro floating-point noise
        if (abs($previousPrice - $livePrice) < 0.01 && ! empty($points)) {
            return;
        }

        ProductPriceHistory::create([
            'product_id' => $this->id,
            'price' => $livePrice,
            'recorded_at' => $when,
        ]);

        $points[] = ['p' => $livePrice, 'd' => $when->format('d/m')];
        $compactPoints = array_slice($points, -10);

        $prices = array_column($compactPoints, 'p');

        $this->lowest_price = ! empty($prices) ? min($prices) : $livePrice;
        $this->highest_price = ! empty($prices) ? max($prices) : $livePrice;
        $this->price_history_json = $compactPoints;
    }

    /**
     * Compact price_history_json cache window decoded for views & charts.
     *
     * @return array<int, array{date: string, price: float}>
     */
    public function getPriceHistoryPoints(): array
    {
        $points = (array) ($this->price_history_json ?? []);

        return array_map(
            fn (array $point): array => [
                'date' => (string) ($point['d'] ?? ''),
                'price' => (float) ($point['p'] ?? 0),
            ],
            $points
        );
    }

    /**
     * Real lowest recorded selling price pulled strictly from actual tracked history points.
     */
    public function getLowestRecordedPrice(): float
    {
        $current = (float) $this->price;
        $points = $this->getPriceHistoryPoints();

        if (empty($points)) {
            $cached = (float) $this->lowest_price;

            return $cached > 0 ? $cached : $current;
        }

        $prices = array_column($points, 'price');
        if ($current > 0) {
            $prices[] = $current;
        }

        $valid = array_filter($prices, fn ($p) => $p > 0);

        return ! empty($valid) ? min($valid) : $current;
    }

    /**
     * Real highest recorded selling price pulled strictly from actual tracked history points.
     * Never mixes with Amazon list price (original_price) to prevent data mismatch.
     */
    public function getHighestRecordedPrice(): float
    {
        $current = (float) $this->price;
        $points = $this->getPriceHistoryPoints();

        if (empty($points)) {
            $cached = (float) $this->highest_price;

            return $cached > 0 ? $cached : $current;
        }

        $prices = array_column($points, 'price');
        if ($current > 0) {
            $prices[] = $current;
        }

        $valid = array_filter($prices, fn ($p) => $p > 0);

        return ! empty($valid) ? max($valid) : $current;
    }

    /**
     * Classify current price status against real historical tracked selling range.
     */
    public function getPriceStatus(): array
    {
        $current = (float) $this->price;
        $lowest = $this->getLowestRecordedPrice();
        $highest = $this->getHighestRecordedPrice();

        if ($current <= 0) {
            return [
                'status' => 'fair',
                'label' => 'السعر قيد التحديث ⏳',
                'color' => 'sky',
            ];
        }

        if (abs($current - $lowest) < 0.01) {
            return [
                'status' => 'excellent',
                'label' => 'أفضل سعر سُجِّل حتى الآن 🔥',
                'color' => 'emerald',
            ];
        }

        if (abs($current - $highest) < 0.01 && $highest > $lowest) {
            return [
                'status' => 'high',
                'label' => 'سعر مرتفع نسبياً ⚠️',
                'color' => 'rose',
            ];
        }

        return [
            'status' => 'fair',
            'label' => 'سعر متوازن للشراء ⚖️',
            'color' => 'sky',
        ];
    }
}

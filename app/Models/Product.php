<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model
{
    public const SYNC_STATUS_PENDING = 'pending';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

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
        'reviews_scraped_at',
        'in_stock',
        'supports_installment',
        'is_active',
        'platform',
        'sync_status',
        'sync_attempts',
        'last_sync_error',
        'last_synced_at',
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
        ];
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
     * Log a price snapshot ONLY when the price actually changed (or when we
     * have no cached history yet). While doing so it updates the memoized
     * lowest_price / highest_price columns and rolls the compact
     * price_history_json cache window forward — all inside the caller's
     * single product UPDATE, keeping the DB ultra-lightweight.
     */
    public function recordPriceHistory(float $livePrice, ?CarbonInterface $when = null, ?float $previousPrice = null): void
    {
        // A zero or negative snapshot is never a real price — it normally means
        // the scraper/import failed. Recording it would permanently floor the
        // lowest_price column (0.00 is string-truthy in PHP, defeating `?:`).
        if ($livePrice <= 0) {
            return;
        }

        $when ??= now();
        $previousPrice ??= (float) $this->price;

        $points = (array) $this->price_history_json;

        // Golden rule #1 — zero rows when the price did not move.
        if (abs($previousPrice - $livePrice) < 0.01 && $points !== []) {
            return;
        }

        ProductPriceHistory::create([
            'product_id' => $this->id,
            'price' => $livePrice,
            'recorded_at' => $when,
        ]);

        // Golden rule #2 — maintain ready-made range columns incrementally.
        // A stored 0.00 (string-truthy) is treated as poison and replaced by the
        // real snapshot so legacy records self-heal on the next price change.
        $this->lowest_price = (float) ($this->lowest_price ?? 0) > 0 && (float) $this->lowest_price <= $livePrice
            ? $this->lowest_price
            : $livePrice;

        $this->highest_price = (float) ($this->highest_price ?? 0) > 0 && (float) $this->highest_price >= $livePrice
            ? $this->highest_price
            : $livePrice;

        // Roll the compact JSON window forward (max 10 points).
        $points[] = ['p' => $livePrice, 'd' => $when->format('d/m')];

        $this->price_history_json = array_slice($points, -10);
    }

    /**
     * Pre-calculated lowest recorded EGP price bounded safely by current price.
     */
    public function getLowestRecordedPrice(): float
    {
        $current = (float) $this->price;

        if ($current <= 0) {
            return 0;
        }

        // lowest_price is a decimal:2 column → comes back as the STRING "0.00",
        // which is truthy in PHP and would defeat `?:`. Compare numerically so a
        // zero snapshot can never leak through as "lowest = 0".
        $lowest = (float) $this->lowest_price;

        if ($lowest <= 0) {
            $lowest = $current;
        }

        // Guarantees lowest recorded is NEVER higher than current live price.
        return min($lowest, $current);
    }

    /**
     * Pre-calculated highest recorded EGP price bounded safely by original price or historical max.
     */
    public function getHighestRecordedPrice(): float
    {
        $current = (float) $this->price;
        $original = (float) ($this->original_price ?? 0);
        // highest_price is decimal:2 → string "0.00" is truthy in PHP; compare
        // numerically so a zero snapshot never becomes the recorded high.
        $highest = (float) $this->highest_price;

        if ($highest <= 0) {
            $highest = max($original, $current * 1.12);
        }

        // Guarantees highest recorded is NEVER lower than current or original price.
        return max($highest, $current, $original);
    }

    /**
     * Classify the current price against the cached historical range.
     * Pure in-memory — executes zero additional queries.
     *
     * @return array{status: 'excellent'|'fair'|'high', label: string, color: 'emerald'|'sky'|'rose'}
     */
    public function getPriceStatus(): array
    {
        $current = (float) $this->price;
        $lowest = $this->getLowestRecordedPrice();
        $highest = $this->getHighestRecordedPrice();

        if ($current <= 0) {
            return [
                'status' => 'fair',
                'label' => 'السعر قيد التحديث',
                'color' => 'sky',
            ];
        }

        if (abs($current - $lowest) < 0.01) {
            return [
                'status' => 'excellent',
                'label' => 'أفضل سعر سُجِّل حتى الآن',
                'color' => 'emerald',
            ];
        }

        if ($current >= $highest * 0.95) {
            return [
                'status' => 'high',
                'label' => 'سعر مرتفع نسبياً',
                'color' => 'rose',
            ];
        }

        return [
            'status' => 'fair',
            'label' => 'سعر متوازن للشراء',
            'color' => 'sky',
        ];
    }

    /**
     * The compact price_history_json cache decoded into chart-ready points
     * (oldest first). Pure in-memory — executes zero additional queries.
     *
     * @return array<int, array{date: string, price: float}>
     */
    public function getPriceHistoryPoints(): array
    {
        return collect((array) $this->price_history_json)
            // Zero/negative snapshots are scraper/import artifacts, never prices.
            ->filter(fn (array $point): bool => (float) ($point['p'] ?? 0) > 0)
            ->map(fn (array $point): array => [
                'date' => (string) ($point['d'] ?? ''),
                'price' => (float) ($point['p'] ?? 0),
            ])
            ->values()
            ->all();
    }
}

<?php

namespace App\Models;

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

    protected $fillable = [
        'category_id',
        'title',
        'asin',
        'brand',
        'price',
        'original_price',
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

    /**
     * Products awaiting ingestion by the catalog scraper: active + pending status,
     * never-synced first (NULLS FIRST), then oldest-to-newest.
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
}

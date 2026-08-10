<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    protected $fillable = [
        'product_id',
        'category_id',
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Pivot rows managed from the Filament listicle section.
     *
     * @return HasMany<ArticleProduct, $this>
     */
    public function articleProducts(): HasMany
    {
        return $this->hasMany(ArticleProduct::class, 'article_id');
    }

    /**
     * The comparison/round-up products, ordered by their pivot sort_order.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'article_product')
            ->withPivot(['sort_order', 'badge_label', 'quick_verdict', 'specs_json', 'specs_markdown'])
            ->orderBy('article_product.sort_order');
    }
}

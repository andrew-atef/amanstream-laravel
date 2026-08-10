<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot model for an article↔product comparison entry (listicle row).
 */
class ArticleProduct extends Model
{
    protected $table = 'article_product';

    public $timestamps = true;

    protected $fillable = [
        'article_id',
        'product_id',
        'sort_order',
        'badge_label',
        'quick_verdict',
        'specs_json',
        'specs_markdown',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'specs_json' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
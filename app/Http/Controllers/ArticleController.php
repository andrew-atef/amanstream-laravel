<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\ShortcodeParser;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function __construct(private readonly ShortcodeParser $shortcodeParser) {}

    /**
     * Resolve a published article by slug and render its parsed content.
     */
    public function show(string $slug): View
    {
        $article = Article::query()
            ->with(['product.category', 'category', 'products', 'articleProducts.product'])
            ->where('slug', $slug)
            ->where('type', 'review')
            ->where('is_published', true)
            ->firstOrFail();

        $parsedContent = $this->shortcodeParser->parse($article);

        // مقالات من نفس القسم (مع استبعاد المقال الحالي) — للـ Dwell Time وعدم الارتداد
        $relatedArticles = Article::query()
            ->with(['product', 'products', 'category'])
            ->where('type', 'review')
            ->whereNotNull('product_id')
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->where('is_published', true)
            ->latest()
            ->limit(8)
            ->get();

        // أقوى العروض الجارية عبر SQL Join لتنفيذ فائق السرعة وبدون استعلامات فرعية
        $topDeals = Article::query()
            ->join('products', 'articles.product_id', '=', 'products.id')
            ->where('articles.is_published', true)
            ->where('articles.type', 'review')
            ->where('articles.id', '!=', $article->id)
            ->where('products.in_stock', true)
            ->where('products.original_price', '>', 0)
            ->whereColumn('products.original_price', '>', 'products.price')
            ->with(['category', 'product', 'products'])
            ->select('articles.*')
            ->selectRaw('((products.original_price - products.price) / products.original_price) * 100 as discount_percentage')
            ->orderByDesc('discount_percentage')
            ->take(8)
            ->get();

        return view('articles.show', [
            'article' => $article,
            'product' => $article->product,
            'parsedContent' => $parsedContent,
            'relatedArticles' => $relatedArticles,
            'topDeals' => $topDeals,
        ]);
    }
}

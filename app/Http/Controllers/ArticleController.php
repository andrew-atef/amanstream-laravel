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
            ->where('is_published', true)
            ->firstOrFail();

        $parsedContent = $this->shortcodeParser->parse($article);

        // مقالات من نفس القسم (مع استبعاد المقال الحالي) — للـ Dwell Time وعدم الارتداد
        $relatedArticles = Article::query()
            ->with(['product', 'products', 'category'])
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->where('is_published', true)
            ->latest()
            ->limit(8)
            ->get();

        // أقوى العروض الجارية (منتجات لها خصم حقيقي) — لتقوية lid Search Intent
        $topDeals = Article::query()
            ->with(['product', 'products', 'category'])
            ->where('is_published', true)
            ->where('id', '!=', $article->id)
            ->whereHas('product', function ($q) {
                $q->whereColumn('original_price', '>', 'price');
            })
            ->latest()
            ->limit(8)
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

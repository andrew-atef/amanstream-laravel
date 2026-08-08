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

        return view('articles.show', [
            'article' => $article,
            'product' => $article->product,
            'parsedContent' => $parsedContent,
        ]);
    }
}

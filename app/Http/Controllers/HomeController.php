<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->query('q'));
        $categorySlug = $request->query('category');
        $dealsOnly = $request->boolean('deals', false);

        $query = Article::query()
            ->with(['category', 'product', 'products'])
            ->where('is_published', true);

        // Live search across title, content, product title, brand and ASIN.
        if (! empty($search)) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($query) use ($search) {
                        $query->where('title', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('asin', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by the selected category slug.
        if (! empty($categorySlug)) {
            $query->whereHas('category', function ($query) use ($categorySlug) {
                $query->where('slug', $categorySlug);
            });
        }

        // "العروض فقط" quick filter: only articles with a live discount.
        if ($dealsOnly) {
            $query->whereHas('product', function ($query) {
                $query->where('in_stock', true)
                    ->whereColumn('original_price', '>', 'price');
            });
        }

        $articles = $query->latest()->paginate(12)->withQueryString();

        // Categories that actually have published articles, with real counts.
        $categories = Category::query()
            ->withCount(['articles' => fn ($query) => $query->where('is_published', true)])
            ->whereHas('articles', fn ($query) => $query->where('is_published', true))
            ->get();

        $totalArticlesCount = Article::where('is_published', true)->count();
        $selectedCategory = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;

        // Top 8 deals: published + in-stock discounts, strongest % first.
        $topDeals = Article::query()
            ->with(['category', 'product', 'products'])
            ->where('is_published', true)
            ->whereHas('product', function ($query) {
                $query->where('in_stock', true)
                    ->whereColumn('original_price', '>', 'price');
            })
            ->get()
            ->sortByDesc(fn (Article $article) => $article->product && (float) $article->product->original_price > 0
                ? (((float) $article->product->original_price - (float) $article->product->price) / (float) $article->product->original_price) * 100
                : 0)
            ->take(8)
            ->values();

        return view('home', [
            'articles' => $articles,
            'categories' => $categories,
            'totalArticlesCount' => $totalArticlesCount,
            'selectedCategory' => $selectedCategory,
            'searchQuery' => $search,
            'dealsOnly' => $dealsOnly,
            'topDeals' => $topDeals,
        ]);
    }
}
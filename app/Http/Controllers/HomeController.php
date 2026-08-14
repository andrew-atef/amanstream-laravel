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
        // Category hub pages use the clean /category/{slug} route; `?category=`
        // remains supported for backwards-compat (old indexed URLs / quick links).
        $categorySlug = $request->route('slug') ?? $request->query('category') ?? null;
        $categoryPage = $request->route('slug') !== null;

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

        // Top 8 deals: ranking and limit computed 100% inside the SQL engine
        // (join + discount_percentage alias), so we never hydrate every deal
        // article into RAM on a 2,000+ article catalog.
        $topDeals = Article::query()
            ->join('products', 'articles.product_id', '=', 'products.id')
            ->where('articles.is_published', true)
            ->where('products.in_stock', true)
            ->where('products.original_price', '>', 0)
            ->whereColumn('products.original_price', '>', 'products.price')
            ->with(['category', 'product', 'products'])
            ->select('articles.*')
            ->selectRaw('((products.original_price - products.price) / products.original_price) * 100 as discount_percentage')
            ->orderByDesc('discount_percentage')
            ->take(8)
            ->get();

        return view('home', [
            'articles' => $articles,
            'categories' => $categories,
            'totalArticlesCount' => $totalArticlesCount,
            'selectedCategory' => $selectedCategory,
            'searchQuery' => $search,
            'dealsOnly' => $dealsOnly,
            'topDeals' => $topDeals,
            'categoryPage' => $categoryPage,
        ]);
    }
}
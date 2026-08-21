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
        $comparisonsOnly = $request->boolean('comparisons', false);

        $query = Article::query()
            ->with(['category', 'product', 'products', 'articleProducts.product'])
            ->where('is_published', true)
            // The home feed is a product-review surface: editorial blog posts
            // (type=blog) live on /blog and must never render as deal/product
            // cards with a phantom "0 ج.م" price.
            ->where('type', 'review');

        // Multi-product comparison rounds have no single price or rating, so a
        // plain browse feed must not pass one off as a deal card. They stay
        // reachable in the dedicated "مقارنات" slider and (?comparisons=1)
        // mode, category hubs (/category/{slug}) and on search.
        if ($search === '' && $categorySlug === null && ! $comparisonsOnly) {
            $query->whereNotNull('product_id');
        }

        // Dedicated comparisons listing (backed by the home "مقارنات" slider's
        // "المزيد" link): browse every multi-product comparison round.
        if ($comparisonsOnly) {
            $query->comparisons();
        }

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

        // The four product-heavy sections behind the "الأكثر بحثاً" quick pills.
        // Ranked live by active product count so the pills never hardcode a
        // section that drifted away from the catalog.
        $trendingCategories = Category::query()
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('products_count')
            ->take(4)
            ->get();

        // Top 8 deals: ranking and limit computed 100% inside the SQL engine
        // (join + discount_percentage alias), so we never hydrate every deal
        // article into RAM on a 2,000+ article catalog.
        $topDeals = Article::query()
            ->join('products', 'articles.product_id', '=', 'products.id')
            ->where('articles.is_published', true)
            ->where('articles.type', 'review')
            ->where('products.in_stock', true)
            ->where('products.original_price', '>', 0)
            ->whereColumn('products.original_price', '>', 'products.price')
            ->with(['category', 'product', 'products', 'articleProducts.product'])
            ->select('articles.*')
            ->selectRaw('((products.original_price - products.price) / products.original_price) * 100 as discount_percentage')
            ->orderByDesc('discount_percentage')
            ->take(8)
            ->get();

        // Latest comparison rounds for the dedicated home slider. Titles are
        // long and decision-critical, so the slider renders them unclamped.
        $comparisons = Article::query()
            ->comparisons()
            ->with(['category', 'product', 'products', 'articleProducts.product'])
            ->where('is_published', true)
            ->latest()
            ->take(8)
            ->get();

        return view('home', [
            'articles' => $articles,
            'categories' => $categories,
            'trendingCategories' => $trendingCategories,
            'totalArticlesCount' => $totalArticlesCount,
            'selectedCategory' => $selectedCategory,
            'searchQuery' => $search,
            'dealsOnly' => $dealsOnly,
            'comparisonsOnly' => $comparisonsOnly,
            'topDeals' => $topDeals,
            'comparisons' => $comparisons,
            'categoryPage' => $categoryPage,
        ]);
    }
}

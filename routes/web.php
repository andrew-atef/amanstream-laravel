<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\SitemapController;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $search = trim($request->query('q'));
    $categorySlug = $request->query('category');

    $query = Article::query()
        ->with(['category', 'product'])
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

    $articles = $query->latest()->paginate(12)->withQueryString();

    // Categories that actually have published articles, with real counts.
    $categories = Category::query()
        ->withCount(['articles' => fn ($query) => $query->where('is_published', true)])
        ->whereHas('articles', fn ($query) => $query->where('is_published', true))
        ->get();

    $totalArticlesCount = Article::where('is_published', true)->count();
    $selectedCategory = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;

    return view('home', [
        'articles' => $articles,
        'categories' => $categories,
        'totalArticlesCount' => $totalArticlesCount,
        'selectedCategory' => $selectedCategory,
        'searchQuery' => $search,
    ]);
})->name('home');

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/articles/{slug}', [ArticleController::class, 'show'])
    ->name('articles.show');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])
    ->name('sitemap');

Route::get('/robots.txt', function () {
    $sitemapUrl = config('app.url').'/sitemap.xml';

    $robots = <<<TXT
User-agent: Googlebot
Disallow:

User-agent: Bingbot
Disallow:

User-agent: GPTBot
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: *
Disallow: /admin
Disallow: /cart

Sitemap: {$sitemapUrl}
TXT;

    return response($robots)->header('Content-Type', 'text/plain');
})->name('robots');

Route::get('/{key}.txt', function (string $key) {
    $configuredKey = config('services.indexnow.key');

    if (blank($configuredKey) || $key !== $configuredKey) {
        abort(404);
    }

    return response($configuredKey)->header('Content-Type', 'text/plain');
})->where('key', '[A-Za-z0-9_-]+')->name('indexnow.key');

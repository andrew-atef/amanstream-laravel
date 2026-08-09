<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

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

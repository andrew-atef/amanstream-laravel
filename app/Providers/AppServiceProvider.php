<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Observers\ArticleObserver;
use App\Observers\ProductObserver;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;
use App\Services\Amazon\PlaceholderAmazonProductDataFetcher;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AmazonProductDataFetcher::class, PlaceholderAmazonProductDataFetcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::anonymousComponentPath(resource_path('views'));

        Article::observe(ArticleObserver::class);
        Product::observe(ProductObserver::class);

        // مشاركة التصنيفات ديناميكياً في الهيدر/الفوتر لجميع الصفحات
        if (Schema::hasTable('categories')) {
            View::share('headerCategories', Category::query()
                ->withCount(['articles' => fn ($query) => $query->where('is_published', true)])
                ->whereHas('articles', fn ($query) => $query->where('is_published', true))
                ->orderBy('name')
                ->limit(8)
                ->get());
        }

        // إجبار Laravel و Livewire على استخدام https في جميع الروابط والركام
        if (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }
}

<?php

namespace App\Providers;

use App\Models\Article;
use App\Observers\ArticleObserver;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;
use App\Services\Amazon\PlaceholderAmazonProductDataFetcher;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
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

        // إجبار Laravel و Livewire على استخدام https في جميع الروابط والركام
        if (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }
}

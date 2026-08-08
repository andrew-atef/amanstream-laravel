<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Observers\ArticleObserver;
use App\Observers\ProductObserver;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;
use App\Services\Amazon\PlaceholderAmazonProductDataFetcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewFactory;

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

        // مشاركة التصنيفات ديناميكياً مع الهيدر/الفوتر فقط، من كاش يومي بدلاً من
        // استعلامٍ غير مخزَّن على كل طلب HTTP.
        View::composer(['layouts.*', 'partials.*'], function (ViewFactory $view): void {
            $view->with('headerCategories', $this->headerCategories());
        });

        // إجبار Laravel و Livewire على استخدام https في جميع الروابط والركام
        if (config('app.env') !== 'local' || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }

    /**
     * Load the shared header category list, cached for a full day. The table
     * check runs before the cache block so unmigrated/fresh installs fail safe.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    protected function headerCategories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return new Collection();
        }

        return Cache::remember('header_categories', now()->addDay(), fn () => Category::query()
            ->withCount(['articles' => fn ($query) => $query->where('is_published', true)])
            ->whereHas('articles', fn ($query) => $query->where('is_published', true))
            ->orderBy('name')
            ->limit(8)
            ->get());
    }
}

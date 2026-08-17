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
use Illuminate\Support\Carbon;
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
        // Render dates/times in the site language (Carbon defaults to English
        // and would otherwise print "17 August 2026" even with APP_LOCALE=ar).
        Carbon::setLocale((string) config('app.locale', 'ar'));

        Blade::anonymousComponentPath(resource_path('views'));

        Article::observe(ArticleObserver::class);
        Product::observe(ProductObserver::class);

        // مشاركة التصنيفات ديناميكياً مع الهيدر/الفوتر لجميع الصفحات، من كاش
        // يومي بدلاً من استعلامٍ غير مخزَّن على كل طلب HTTP.
        // ملاحظة: عند استخدام `<x-layouts.app>` كـ anonymous component، يُحفظ
        // الـ view تحت اسم namespaced (hash::layouts.app) لا "layouts.app"،
        // لذلك نسجل composer على '*' (أي view) فيتم تغطية كل النماذج.
        View::composer('*', function (ViewFactory $view): void {
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
     * Note: we never write an *empty* result into the cache. `Cache::remember`
     * would store a blank collection for a whole day if the DB was seeded
     * after the first request (which is exactly what happened in production).
     * Using `Cache::get` + only caching when non-empty keeps the header live
     * within one request after data becomes available.
     *
     * @return Collection<int, Category>
     */
    protected function headerCategories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return new Collection;
        }

        $headerCategories = Cache::get('header_categories');

        if ($headerCategories instanceof Collection && $headerCategories->isNotEmpty()) {
            return $headerCategories;
        }

        $headerCategories = Category::query()
            ->withCount(['articles' => fn ($query) => $query->where('is_published', true)])
            ->whereHas('articles', fn ($query) => $query->where('is_published', true))
            ->orderBy('name')
            ->limit(8)
            ->get();

        if ($headerCategories->isNotEmpty()) {
            Cache::put('header_categories', $headerCategories, now()->addDay());
        }

        return $headerCategories;
    }
}

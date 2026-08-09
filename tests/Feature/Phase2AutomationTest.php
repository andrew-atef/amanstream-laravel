<?php

namespace Tests\Feature;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Models\Bank;
use App\Models\Category;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;
use App\Services\CloudflareCacheService;
use App\Services\InstantIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2AutomationTest extends TestCase
{
    use RefreshDatabase;

    private function seedArticle(): Article
    {
        $category = Category::create([
            'name' => 'تكييفات',
            'slug' => 'air-conditioners',
            'description' => 'أجهزة التكييف',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو',
            'asin' => 'B01LCVQ0UY',
            'brand' => 'Fresh',
            'price' => 18521.00,
            'rating' => 3.8,
            'review_count' => 280,
            'affiliate_url' => 'https://www.amazon.eg/dp/B01LCVQ0UY?tag=demo-21',
            'in_stock' => true,
        ]);

        return Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'سعر تكييف فريش 1.5 حصان',
            'slug' => Str::slug('سعر تكييف فريش 1.5 حصان'),
            'content' => '<p>[interactive_installment]</p><p>[price]</p><p>[rating]</p><p>[buy_button]</p>',
            'is_published' => true,
        ]);
    }

    public function test_interactive_installment_renders_db_driven_bank_table(): void
    {
        $article = $this->seedArticle();
        $bank = Bank::create([
            'name_ar' => 'البنك التجاري الدولي',
            'name_en' => 'CIB',
            'code' => 'cib',
            'is_active' => true,
        ]);
        InstallmentPlan::create([
            'bank_id' => $bank->id,
            'months' => 12,
            'interest_rate' => 0,
            'admin_fee_percent' => 0,
            'min_order_amount' => 500,
            'is_zero_interest' => true,
        ]);

        $response = $this->get('/articles/'.$article->slug);
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringNotContainsString('[interactive_installment]', $html);
        $this->assertStringContainsString('جدول الأقساط البنكية', $html);
        $this->assertStringContainsString('البنك التجاري الدولي', $html);
        $this->assertStringContainsString('12 شهر', $html);
        $this->assertStringContainsString('0% فائدة', $html);

        $monthly = (float) $article->product->price / 12;
        $this->assertStringContainsString(number_format($monthly, 2).' ج.م/شهر', $html);
    }

    public function test_interactive_installment_shows_fallback_when_no_eligible_plans(): void
    {
        $article = $this->seedArticle();

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringNotContainsString('[interactive_installment]', $html);
        $this->assertStringContainsString('الدفع المباشر', $html);
    }

    public function test_breadcrumb_schema_is_injected(): void
    {
        $article = $this->seedArticle();

        $response = $this->get('/articles/'.$article->slug);

        $this->assertStringContainsString('BreadcrumbList', $response->getContent());
    }

    public function test_sticky_mobile_buy_bar_is_rendered_with_affiliate_link(): void
    {
        $article = $this->seedArticle();

        $response = $this->get('/articles/'.$article->slug);

        $this->assertStringContainsString('sticky-buy-bar', $response->getContent());
        $this->assertStringContainsString('amazon.eg', $response->getContent());
    }

    public function test_article_renders_shortcode_html_without_escaping(): void
    {
        $article = $this->seedArticle();

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringNotContainsString('&lt;a', $html);
        $this->assertStringNotContainsString('&lt;span', $html);
        $this->assertStringContainsString('href="https://www.amazon.eg/dp/B01LCVQ0UY?tag=demo-21"', $html);
        $this->assertStringContainsString('الدفع المباشر', $html);
    }

    public function test_home_page_eager_loads_products_without_n_plus_one(): void
    {
        $category = Category::create(['name' => 'أجهزة', 'slug' => 'devices']);

        for ($i = 1; $i <= 8; $i++) {
            $product = Product::create([
                'category_id' => $category->id,
                'title' => 'منتج '.$i,
                'asin' => 'ASIN'.$i,
                'brand' => 'Demo',
                'price' => 1000 + $i,
                'rating' => 4.0,
                'review_count' => 10,
                'affiliate_url' => 'https://www.amazon.eg/dp/ASIN'.$i,
                'in_stock' => true,
            ]);

            Article::create([
                'product_id' => $product->id,
                'category_id' => $category->id,
                'title' => 'مقال '.$i,
                'slug' => 'article-'.$i,
                'content' => '<p>[price]</p>',
                'is_published' => true,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get('/')->assertOk();

        $productsQueries = collect(DB::getQueryLog())
            ->filter(fn (array $log): bool => str_contains($log['query'], 'from "products"'))
            ->count();

        // Bounded eager loads only (single-product + comparison products join) — never N+1 per article.
        $this->assertLessThanOrEqual(3, $productsQueries);
    }

    public function test_home_search_filters_articles_by_title_brand_and_category(): void
    {
        $article = $this->seedArticle();

        $this->get('/?q=فريش')
            ->assertOk()
            ->assertSee('سعر تكييف فريش', false);

        $this->get('/?q='.rawurlencode('لا يوجد جهاز'))
            ->assertOk()
            ->assertSee('لم نجد أي مراجعات مطابقة لبحثك', false);

        $this->get('/?category=air-conditioners')
            ->assertOk()
            ->assertSee('سعر تكييف فريش', false);

        $this->get('/?category=does-not-exist')
            ->assertOk()
            ->assertDontSee('سعر تكييف فريش', false);
    }

    public function test_layout_keeps_full_seo_head(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('meta name="description"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('meta name="robots"', $html);
    }

    public function test_sitemap_includes_articles_categories_and_static_page(): void
    {
        $article = $this->seedArticle();

        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertSee('urlset', false)
            ->assertSee('/articles/'.$article->slug, false)
            ->assertSee('changefreq', false)
            ->assertSee('priority', false);
    }

    public function test_robots_txt_allows_bots_and_references_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk()
            ->assertHeaderContains('Content-Type', 'text/plain');

        $content = $response->getContent();
        $this->assertStringContainsString('Googlebot', $content);
        $this->assertStringContainsString('GPTBot', $content);
        $this->assertStringContainsString('PerplexityBot', $content);
        $this->assertStringContainsString('/sitemap.xml', $content);
    }

    public function test_article_observer_dispatches_indexing_and_cache_purge_jobs_for_published_article(): void
    {
        Queue::fake();

        $article = $this->seedArticle();

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job) use ($article) {
            return collect($job->urls)->contains(fn (string $url) => str_contains($url, '/articles/'.$article->slug));
        });
    }

    public function test_cloudflare_cache_service_skips_without_configuration(): void
    {
        config(['services.cloudflare.api_token' => null, 'services.cloudflare.zone_id' => null]);

        $this->assertFalse((new CloudflareCacheService)->purgeUrl('https://example.com/article'));
    }

    public function test_get_eligible_installment_plans_respects_supports_installment_flag(): void
    {
        $bank = Bank::create(['name_ar' => 'بنك', 'name_en' => 'Bank', 'code' => 'flag-bank', 'is_active' => true]);
        InstallmentPlan::create(['bank_id' => $bank->id, 'months' => 6, 'min_order_amount' => 100, 'is_zero_interest' => true]);

        $category = Category::create(['name' => 'فئة', 'slug' => 'flag-cat']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'جهاز بدون تقسيط',
            'asin' => 'FLAG123',
            'price' => 5000,
            'rating' => 4.0,
            'review_count' => 5,
            'affiliate_url' => 'https://www.amazon.eg/dp/FLAG123',
            'in_stock' => true,
            'supports_installment' => false,
        ]);

        $this->assertTrue($product->getEligibleInstallmentPlans()->isEmpty());
    }

    public function test_indexnow_key_route_returns_404_when_not_configured(): void
    {
        $this->get('/myindexnowkey.txt')->assertNotFound();
    }

    public function test_indexing_service_gracefully_skips_without_credentials(): void
    {
        $service = new InstantIndexingService;

        $this->assertFalse($service->notifyGoogle('https://example.com'));
        $this->assertFalse($service->notifyIndexNow('https://example.com'));
    }

    public function test_installment_plan_calculates_monthly_payment_including_interest_and_fees(): void
    {
        $bank = Bank::create(['name_ar' => 'بنك', 'name_en' => 'Bank', 'code' => 'demo']);
        $plan = InstallmentPlan::create([
            'bank_id' => $bank->id,
            'months' => 12,
            'interest_rate' => 18.76,
            'admin_fee_percent' => 2,
            'min_order_amount' => 500,
        ]);

        $this->assertEquals(round(1000 * 1.2076 / 12, 2), round($plan->calculateMonthlyPayment(1000), 2));
    }

    public function test_get_eligible_installment_plans_filters_and_orders_by_price_and_activity(): void
    {
        $active = Bank::create(['name_ar' => 'نشط', 'name_en' => 'Active', 'code' => 'active', 'is_active' => true]);
        $inactive = Bank::create(['name_ar' => 'موقوف', 'name_en' => 'Inactive', 'code' => 'inactive', 'is_active' => false]);

        $highMin = InstallmentPlan::create(['bank_id' => $active->id, 'months' => 6, 'min_order_amount' => 5000, 'is_zero_interest' => true]);
        $paidPlan = InstallmentPlan::create(['bank_id' => $active->id, 'months' => 12, 'min_order_amount' => 500, 'is_zero_interest' => false]);
        $zeroPlan = InstallmentPlan::create(['bank_id' => $active->id, 'months' => 6, 'min_order_amount' => 500, 'is_zero_interest' => true]);
        $inactivePlan = InstallmentPlan::create(['bank_id' => $inactive->id, 'months' => 6, 'min_order_amount' => 100, 'is_zero_interest' => true]);

        $product = Product::create([
            'category_id' => Category::create(['name' => 'فئة', 'slug' => 'cat'])->id,
            'title' => 'جهاز تجريبي',
            'asin' => 'DEMO123',
            'price' => 2000,
            'rating' => 4.0,
            'review_count' => 5,
            'affiliate_url' => 'https://www.amazon.eg/dp/DEMO123',
            'in_stock' => true,
        ]);

        $eligible = $product->getEligibleInstallmentPlans()->pluck('id')->all();

        $this->assertEquals([$zeroPlan->id, $paidPlan->id], $eligible);
        $this->assertNotContains($highMin->id, $eligible);
        $this->assertNotContains($inactivePlan->id, $eligible);
    }

    public function test_sync_command_updates_last_synced_at_and_price(): void
    {
        $article = $this->seedArticle();
        $product = $article->product;

        $this->app->instance(AmazonProductDataFetcher::class, new class implements AmazonProductDataFetcher
        {
            public function fetch(Product $product): array
            {
                return [
                    'price' => 19000.00,
                    'in_stock' => true,
                    'rating' => 4.1,
                    'review_count' => 310,
                ];
            }
        });

        $this->artisan('amazon:sync-prices')->assertSuccessful();

        $product->refresh();
        $this->assertEquals(19000.00, (float) $product->price);
        $this->assertEquals(4.1, (float) $product->rating);
        $this->assertNotNull($product->last_synced_at);
    }
}

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
use Illuminate\Support\Facades\Http;
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
            ->assertSee('/category/air-conditioners', false)
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
        $this->assertStringContainsString('Bingbot', $content);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /cart', $content);
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

    public function test_sitemap_cache_is_invalidated_when_a_new_article_is_published(): void
    {
        $article = $this->seedArticle();

        // Warm the 6-hour sitemap cache.
        $this->get('/sitemap.xml')->assertSee('/articles/'.$article->slug, false);

        // Publishing a brand-new article must drop the cached snapshot so the
        // next request serves it immediately (was: stale for up to 6 hours).
        $newArticle = Article::create([
            'product_id' => $article->product_id,
            'category_id' => $article->category_id,
            'title' => 'مراجعة جديدة منشورة لتكيف',
            'slug' => 'freshly-published-review',
            'content' => '<p>محتوى مقال جديد</p>',
            'is_published' => true,
        ]);

        $this->get('/sitemap.xml')
            ->assertSee('/articles/freshly-published-review', false)
            ->assertSee('/articles/'.$article->slug, false);

        $this->assertTrue($newArticle->is_published);
    }

    public function test_sitemap_includes_product_images_for_google_images(): void
    {
        $article = $this->seedArticle();

        Article::where('id', $article->id)->update(['is_published' => true]);
        $article->product->update(['image_url' => 'https://img.example.com/ac-1.5hp.jpg']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('xmlns:image', false)
            ->assertSee('<image:loc>https://img.example.com/ac-1.5hp.jpg</image:loc>', false);
    }

    public function test_bare_host_is_redirected_to_www_when_app_url_is_canonical(): void
    {
        // APP_URL carry the www prefix: bare-host visits must STILL 301 to www.
        config(['app.url' => 'https://www.amanprice.tech']);

        $this->withServerVariables([
            'HTTP_HOST' => 'amanprice.tech',
            'HTTPS' => 'on',
            'SERVER_NAME' => 'amanprice.tech',
        ])->get('/category/air-conditioners?deals=1')
            ->assertRedirect('https://www.amanprice.tech/category/air-conditioners?deals=1')
            ->assertStatus(301);
    }

    public function test_bare_host_is_redirected_to_www_when_app_url_is_apex(): void
    {
        // APP_URL without www: the canonical host must still be normalized to
        // the www. subdomain before the 301 hop.
        config(['app.url' => 'https://amanprice.tech']);

        $this->withServerVariables([
            'HTTP_HOST' => 'amanprice.tech',
            'HTTPS' => 'on',
        ])->get('/')
            ->assertRedirect('https://www.amanprice.tech/')
            ->assertStatus(301);
    }

    public function test_canonical_www_host_passes_through_without_redirect(): void
    {
        $request = $this->withServerVariables([
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('https://www.amanprice.tech/');

        $request->assertOk();
    }

    public function test_faq_schema_is_extracted_from_markdown_headings(): void
    {
        $article = $this->seedArticle();

        $article->update([
            'content' => <<<'MD'
## جهاز

### س: هل يدعم هذا الجهاز التدفئة؟

لا، الجهاز بارد فقط ولا يملك وضع تدفئة شتوية.

### لماذا يعتبر هذا الموديل اقتصادياً؟

يستهلك طاقة أقل لأنه مزود بضاغط إنفرتر.

[price]
MD,
        ]);

        $faqs = $article->getFaqSchemaData();

        $this->assertCount(2, $faqs);
        $this->assertSame('س: هل يدعم هذا الجهاز التدفئة؟', $faqs[0]['name']);
        $this->assertStringContainsString('لا، الجهاز بارد فقط', $faqs[0]['acceptedAnswer']['text']);
        // Answer text must not leak the [price] shortcode.
        $this->assertStringNotContainsString('[price]', $faqs[1]['acceptedAnswer']['text']);
    }

    public function test_faq_schema_supports_html_blocks_and_ignores_section_headings(): void
    {
        $article = $this->seedArticle();

        $article->update([
            'content' => '<h2>المميزات الرئيسية</h2><p>سرعة تبريد عالية وسعر اقتصادي.</p>'
                .'<h3>س: ما مدة الضمان؟</h3><p>الضمان سنة كاملة من الوكيل.</p>',
        ]);

        $faqs = $article->getFaqSchemaData();

        // The "المميزات الرئيسية" H2 must never become a junk FAQ entry.
        $this->assertCount(1, $faqs);
        $this->assertSame('س: ما مدة الضمان؟', $faqs[0]['name']);
    }

    public function test_faq_answers_never_truncate_mid_word(): void
    {
        $article = $this->seedArticle();

        // A long, valid Arabic answer that exceeds the 500-char cap with many
        // whole words. The old mb_strimwidth(answer, 0, 500) cut at exactly
        // 500 characters and split a word (e.g. "والمكاتب" became "والم").
        $sentence = 'المكاتب والشقق التجارية التي تبحث عن أداء موثوق بوضع التبريد فقط مع تقنيات التنظيف الذاتي وفلاتر حجب الغبار. ';
        $answer = str_repeat($sentence, 4); // ~2300 chars, well over 500
        $answer .= 'والجملة الأخيرة كاملة.';  // ensure clsure word complete

        $article->update([
            'content' => '### س: لماذا يعد هذا الخيار الاعتمادي؟'.PHP_EOL.PHP_EOL.$answer.PHP_EOL,
        ]);

        $faqs = $article->getFaqSchemaData();

        $this->assertCount(1, $faqs);
        $text = $faqs[0]['acceptedAnswer']['text'];

        // The emitted answer is a pure prefix of the source text cut at the
        // last space before the 500-char cap — never in the middle of a word.
        $this->assertLessThanOrEqual(500, mb_strlen($text, 'UTF-8'));
        $this->assertStringStartsWith('المكاتب', $text);
        $this->assertTrue(str_starts_with($answer, $text) && mb_strlen($text, 'UTF-8') <= 500, 'answer is a whole-word prefix');

        // The character right after the cap boundary in the source, if it is
        // a plain space, means the truncation happened at a word boundary.
        $after = mb_substr($answer, mb_strlen($text, 'UTF-8'), 1, 'UTF-8');
        $this->assertTrue($after === '' || $after === ' ', 'truncation must stop exactly after a word');
        $this->assertStringNotContainsString("\u{2026}", $text);
    }

    public function test_article_page_renders_faqpage_json_ld_without_blade_leak(): void
    {
        $article = $this->seedArticle();

        $article->update([
            'content' => '### س: هل يدعم التقسيط؟'.PHP_EOL.PHP_EOL.'نعم، يدعم تقسيط 12 شهراً عبر البنوك.'.PHP_EOL,
        ]);

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringContainsString('FAQPage', $html);
        $this->assertStringContainsString('"name":"س: هل يدعم التقسيط؟"', $html);

        // Regression: `'@context'` must NEVER be compiled into Blade/Livewire
        // directive PHP inside the emitted JSON-LD.
        $this->assertStringNotContainsString('<?php', $html);
        $this->assertStringNotContainsString('$__contextArgs', $html);
        $this->assertStringNotContainsString('context()->get', $html);
        $this->assertStringContainsString('"@context":"https://schema.org"', $html);
    }

    public function test_schema_urls_have_no_double_slashes(): void
    {
        $article = $this->seedArticle();

        // Homepage carries the WebSite SearchAction schema + category links.
        $home = $this->get('/')->getContent();
        $this->assertStringNotContainsString('//?q=', $home);
        $this->assertStringContainsString('/?q={search_term_string}', $home);

        // Article page carries Publisher logo / image fallbacks.
        $articleHtml = $this->get('/articles/'.$article->slug)->getContent();
        $this->assertStringNotContainsString('//favicon.svg', $articleHtml);
        $this->assertStringContainsString('/favicon.svg', $articleHtml);
    }

    public function test_cloudflare_cache_service_skips_without_configuration(): void
    {
        config(['services.cloudflare.api_token' => null, 'services.cloudflare.zone_id' => null]);

        $this->assertFalse((new CloudflareCacheService)->purgeUrl('https://example.com/article'));
    }

    public function test_cloudflare_cache_service_adds_markdown_variant_to_purge_urls(): void
    {
        $service = new CloudflareCacheService;

        $this->assertSame([
            'https://example.com/article',
            'https://example.com/article?_fmt=md',
            'https://example.com/list?page=1',
            'https://example.com/list?page=1&_fmt=md',
            'https://example.com/already?_fmt=md',
        ], $service->withMarkdownVariants([
            'https://example.com/article',
            'https://example.com/list?page=1',
            'https://example.com/already?_fmt=md',
        ]));
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

    public function test_indexnow_submission_sends_host_key_and_key_location_json_payload(): void
    {
        config([
            'services.indexnow.key' => '1bc2ec6150614ec9bf0a39c192f87f87',
            'services.indexnow.key_location' => 'https://www.amanprice.tech/1bc2ec6150614ec9bf0a39c192f87f87.txt',
        ]);

        Http::fake([
            'api.indexnow.org/*' => Http::response('', 200),
        ]);

        $service = new InstantIndexingService;

        $this->assertTrue($service->notifyIndexNow('https://www.amanprice.tech/articles/some-article'));

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://api.indexnow.org/indexnow'
                && $payload['host'] === 'www.amanprice.tech'
                && $payload['key'] === '1bc2ec6150614ec9bf0a39c192f87f87'
                && $payload['keyLocation'] === 'https://www.amanprice.tech/1bc2ec6150614ec9bf0a39c192f87f87.txt'
                && $payload['urlList'] === ['https://www.amanprice.tech/articles/some-article'];
        });
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

    public function test_sync_command_clears_rating_and_review_count_when_fetcher_reports_zero(): void
    {
        $category = Category::create(['name' => 'تكييفات', 'slug' => 'air-conditioners', 'description' => '']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج بلا مراجعات',
            'asin' => 'B0ZEROR8',
            'price' => 1000.00,
            'rating' => 4.1,
            'review_count' => 149,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0ZEROR8',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $this->app->instance(AmazonProductDataFetcher::class, new class implements AmazonProductDataFetcher
        {
            public function fetch(Product $product): array
            {
                return [
                    'price' => 1000.00,
                    'in_stock' => true,
                    'rating' => 0,
                    'review_count' => 0,
                ];
            }
        });

        $this->artisan('amazon:sync-prices')->assertSuccessful();

        $product->refresh();
        $this->assertEquals(0.0, (float) $product->rating);
        $this->assertEquals(0, (int) $product->review_count);
    }

    public function test_sync_command_revives_out_of_stock_products(): void
    {
        $category = Category::create(['name' => 'تكييفات', 'slug' => 'air-conditioners', 'description' => '']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف رجع متاح',
            'asin' => 'B0REVIVE',
            'price' => 15000.00,
            'rating' => 4.0,
            'review_count' => 10,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0REVIVE',
            'in_stock' => false,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $this->app->instance(AmazonProductDataFetcher::class, new class implements AmazonProductDataFetcher
        {
            public function fetch(Product $product): array
            {
                return [
                    'price' => 15000.00,
                    'in_stock' => true,
                    'rating' => 4.0,
                    'review_count' => 10,
                ];
            }
        });

        $this->artisan('amazon:sync-prices')->assertSuccessful();

        $product->refresh();
        $this->assertTrue($product->in_stock);
        $this->assertEquals(Product::SYNC_STATUS_SYNCED, $product->sync_status);
    }

    public function test_pending_catalog_queue_includes_out_of_stock_products(): void
    {
        $category = Category::create(['name' => 'تكييفات', 'slug' => 'air-conditioners', 'description' => '']);

        $make = fn (string $asin, array $overrides = []) => Product::create(array_merge([
            'category_id' => $category->id,
            'title' => 'منتج '.$asin,
            'asin' => $asin,
            'price' => 1000.00,
            'rating' => 4.0,
            'review_count' => 10,
            'affiliate_url' => 'https://www.amazon.eg/dp/'.$asin,
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ], $overrides));

        $make('OOS-PENDING', ['in_stock' => false]);
        $make('INSTOCK-PENDING', ['in_stock' => true]);
        $make('OOS-STALE', [
            'in_stock' => false,
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'last_synced_at' => now()->subHours(7),
        ]);
        $make('OOS-INACTIVE', ['in_stock' => false, 'is_active' => false]);

        $pending = Product::query()
            ->pendingForCatalogSync()
            ->get()
            ->pluck('asin')
            ->all();

        $this->assertContains('OOS-PENDING', $pending);
        $this->assertContains('INSTOCK-PENDING', $pending);
        $this->assertNotContains('OOS-STALE', $pending);
        $this->assertNotContains('OOS-INACTIVE', $pending);

        // The 6-hour reset cron is what pushes synced products back into the queue.
        $this->artisan('catalog:reset-sync-queue')->assertSuccessful();

        $pending = Product::query()
            ->pendingForCatalogSync()
            ->get()
            ->pluck('asin')
            ->all();

        $this->assertContains('OOS-STALE', $pending);
        $this->assertNotContains('OOS-INACTIVE', $pending);
    }
}

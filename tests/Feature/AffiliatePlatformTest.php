<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AffiliatePlatformTest extends TestCase
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
            'content' => '<p>[summary_box]</p><p>[price]</p><p>[rating]</p><p>[installment]</p><p>[buy_button]</p>',
            'is_published' => true,
        ]);
    }

    public function test_home_page_lists_published_articles(): void
    {
        $article = $this->seedArticle();

        $this->get('/')
            ->assertOk()
            ->assertSee($article->title);
    }

    public function test_article_page_renders_shortcodes_and_schema(): void
    {
        $article = $this->seedArticle();

        $response = $this->get('/articles/'.$article->slug);

        $response->assertOk();
        $response->assertSee('18,521.00 ج.م', false);
        $response->assertSee('nofollow sponsored', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('amazon.eg', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('[price]', $html);
        $this->assertStringNotContainsString('[buy_button]', $html);
        $this->assertStringContainsString('priceCurrency', $html);
        $this->assertStringContainsString('3.8', $html);
    }

    public function test_summary_box_renders_custom_pros_cons_verdict_when_pros_is_first_attribute(): void
    {
        $article = $this->seedArticle();
        // pros comes first — the exact shape that used to fail because the
        // attribute regex required a leading space before the first attribute.
        $article->update(['content' => '[summary_box pros="تبريد تربو سريع للغرف حتى 12-14 م² | سعر اقتصادي يناسب الميزانيات المتوسطة" cons="بارد فقط ولا يدعم وضع التدفئة الشتوية" verdict="خيار عملي واقتصادي ممتاز في حر الصيف."]']);

        $response = $this->get('/articles/'.$article->slug)->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('تبريد تربو سريع للغرف حتى 12-14 م²', $html);
        $this->assertStringContainsString('سعر اقتصادي يناسب الميزانيات المتوسطة', $html);
        $this->assertStringContainsString('بارد فقط ولا يدعم وضع التدفئة الشتوية', $html);
        $this->assertStringContainsString('خيار عملي واقتصادي ممتاز في حر الصيف.', $html);
        // The auto-generated fallback copy must NOT appear when custom copy is given.
        $this->assertStringNotContainsString('علامة تجارية موثوقة داخل السوق المصري', $html);
        $this->assertStringNotContainsString('[summary_box]', $html);
    }

    public function test_unpublished_article_returns_404(): void
    {
        $article = $this->seedArticle();
        $article->update(['is_published' => false]);

        $this->get('/articles/'.$article->slug)->assertNotFound();
    }

    public function test_sitemap_returns_valid_xml(): void
    {
        $article = $this->seedArticle();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertSee('urlset', false)
            ->assertSee('/articles/'.$article->slug, false);
    }

    public function test_admin_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_authenticated_user_can_access_resources(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/products')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/articles')
            ->assertOk();
    }

    public function test_comparison_table_renders_spec_rows_for_markdown_specs(): void
    {
        $category = Category::create([
            'name' => 'تكييفات',
            'slug' => 'air-conditioners',
            'description' => 'أجهزة التكييف',
        ]);

        $haier = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف سبليت HSU12KCSTOC، 1.5 حصان من هاير',
            'asin' => 'B0CSZ1HMQL',
            'brand' => 'Haier',
            'price' => 19440.00,
            'rating' => 3.8,
            'review_count' => 46,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0CSZ1HMQL?tag=demo-21',
            'in_stock' => true,
        ]);

        $fresh = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف سبليت احترافي تبريد توربو فقط من فريش',
            'asin' => 'B01LCVQ0UY',
            'brand' => 'Fresh',
            'price' => 18288.00,
            'rating' => 3.8,
            'review_count' => 281,
            'affiliate_url' => 'https://www.amazon.eg/dp/B01LCVQ0UY?tag=demo-21',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'category_id' => $category->id,
            'title' => 'مقارنة هاير ضد فريش',
            'slug' => 'mkarn-hayr-dd-frysh',
            'content' => "## المقارنة\n\n[comparison_table]\n\n[product_cards]",
            'is_published' => true,
        ]);

        $md = "- **خامات المواسير والكباس:** نحاس خالص 100%\n- **نوع الشاشة:** شاشة ديجيتال رقمية\n- **مستوى الضوضاء:** هادئ (14.4 ديسيبل)";

        $article->articleProducts()->create([
            'product_id' => $haier->id,
            'sort_order' => 1,
            'badge_label' => 'الأقوى خامات',
            'quick_verdict' => 'أفضل خيار للعمر الطويل',
            'specs_markdown' => $md,
        ]);

        $article->articleProducts()->create([
            'product_id' => $fresh->id,
            'sort_order' => 2,
            'badge_label' => 'الأوفر مالياً',
            'quick_verdict' => 'أفضل قيمة مقابل السعر',
            'specs_markdown' => "- **خامات المواسير والكباس:** نحاس معالج محلياً\n- **نوع الشاشة:** شاشة إرشادية بسيطة\n- **مستوى الضوضاء:** هادئ جداً",
        ]);

        $response = $this->get('/articles/'.$article->slug)->assertOk();
        $html = $response->getContent();

        // Comparison table must contain the spec label rows shared by both products.
        $this->assertStringContainsString('خامات المواسير والكباس', $html);
        $this->assertStringContainsString('نوع الشاشة', $html);
        $this->assertStringContainsString('مستوى الضوضاء', $html);
        $this->assertStringContainsString('نحاس خالص 100%', $html);
        $this->assertStringContainsString('نحاس معالج محلياً', $html);

        // Product cards render spec values as standalone rows, not wrapped in **.
        $this->assertStringContainsString('شاشة ديجيتال رقمية', $html);
        $this->assertStringNotContainsString('**خامات المواسير', $html);

        // Search Console flagged the comparison ItemList Product nodes for a
        // missing `image`, missing `description`, and offers without
        // shipping/return policy. Every nested item must now carry them.
        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"image":', $html);
        $this->assertStringContainsString('"shippingDetails"', $html);
        $this->assertStringContainsString('"hasMerchantReturnPolicy"', $html);
        $this->assertStringContainsString('"aggregateRating"', $html);
        $this->assertStringContainsString('"reviewCount":46', $html);
        $this->assertStringContainsString('"reviewCount":281', $html);
    }
}

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
}

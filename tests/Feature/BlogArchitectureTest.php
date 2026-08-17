<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Services\ShortcodeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dual-article architecture: review/comparison content stays at /articles/{slug}
 * while editorial blog posts and guides live under /blog and /blog/{slug}, with
 * no product commerce leaking into the editorial path.
 */
class BlogArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(string $slug = 'blog-cat', string $name = 'أدلة إرشادية'): Category
    {
        return Category::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    private function makeBlogPost(array $overrides = []): Article
    {
        return Article::create(array_merge([
            'type' => 'blog',
            'title' => 'دليل اختيار غسالة بالحجم المناسب',
            'slug' => 'choosing-washer-size',
            'meta_description' => 'دليل عملي لاختيار سعة الغسالة المناسبة.',
            'content' => "## كيف تختار السعة؟\n\nقيس عدد أفراد الأسرة أولاً ثم اختر السعة المناسبة.",
            'is_published' => true,
        ], $overrides));
    }

    private function makeReviewArticle(string $slug = 'review-washer'): Article
    {
        $category = $this->makeCategory('review-cat', 'توصيل كهرباء');
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'غسالة اختبار',
            'asin' => 'REVASIN001',
            'brand' => 'فريش',
            'price' => 8900,
            'original_price' => 9900,
            'affiliate_url' => 'https://www.amazon.eg/dp/REVASIN001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        return Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'type' => 'review',
            'title' => 'مراجعة غسالة اختبار',
            'slug' => $slug,
            'meta_description' => 'مراجعة كاملة.',
            'content' => "## المميزات\n\nمراجعة واقعية للمنتج [price].",
            'is_published' => true,
        ]);
    }

    public function test_blog_index_lists_only_published_blog_posts(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'blog-index-post']);
        $review = $this->makeReviewArticle();

        $response = $this->get('/blog');

        $response->assertOk();
        $response->assertSee($blogPost->title);
        $response->assertDontSee($review->title);
    }

    public function test_blog_show_renders_published_post_without_commerce_components(): void
    {
        $post = $this->makeBlogPost(['slug' => 'blog-show-post']);

        $response = $this->get('/blog/'.$post->slug);

        $response->assertOk();
        $response->assertSee($post->title);
        $response->assertSee('BlogPosting', false);
        $response->assertSee('دقيقة قراءة');
        $response->assertDontSee('اشترِ الآن');
        $response->assertDontSee('sticky-buy-bar');
        $response->assertDontSee('ج.م');
    }

    public function test_blog_show_is_404_for_review_articles_and_unpublished_posts(): void
    {
        $review = $this->makeReviewArticle();
        $draft = $this->makeBlogPost(['slug' => 'blog-draft', 'is_published' => false]);

        $this->get('/blog/'.$review->slug)->assertNotFound();
        $this->get('/blog/'.$draft->slug)->assertNotFound();
    }

    public function test_review_route_is_404_for_blog_posts(): void
    {
        $post = $this->makeBlogPost(['slug' => 'blog-under-review']);

        $this->get('/articles/'.$post->slug)->assertNotFound();
    }

    public function test_blog_markdown_variant_omits_commerce_frontmatter(): void
    {
        $post = $this->makeBlogPost(['slug' => 'blog-md-post', 'category_id' => $this->makeCategory()->id]);

        $response = $this->get('/blog/'.$post->slug, ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('type: blog', $response->getContent());
        $this->assertStringContainsString('category: أدلة إرشادية', $response->getContent());
        $this->assertStringContainsString('/blog/blog-md-post', $response->getContent());
        $this->assertStringNotContainsString('asin', $response->getContent());
        $this->assertStringNotContainsString('offer_url', $response->getContent());
    }

    public function test_blog_posts_do_not_require_a_category(): void
    {
        $post = $this->makeBlogPost(['slug' => 'blog-uncategorized']);

        $this->assertNull($post->category_id);

        $this->get('/blog')->assertOk()->assertSee($post->title);

        $this->get('/blog/'.$post->slug)
            ->assertOk()
            ->assertSee($post->title)
            ->assertSee('BlogPosting', false);

        $md = $this->get('/blog/'.$post->slug, ['Accept' => 'text/markdown']);
        $md->assertOk();
        $this->assertStringContainsString('category: المدونة', $md->getContent());
    }

    public function test_blog_index_markdown_variant_lists_posts(): void
    {
        $post = $this->makeBlogPost(['slug' => 'blog-index-md']);

        $response = $this->get('/blog', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# المدونة والمقالات الإرشادية', $response->getContent());
        $this->assertStringContainsString('/blog/blog-index-md', $response->getContent());
    }

    public function test_sitemap_uses_blog_urls_for_blog_posts_and_articles_urls_for_reviews(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'sitemap-blog']);
        $review = $this->makeReviewArticle('sitemap-review');

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('/blog/sitemap-blog', $response->getContent());
        $this->assertStringContainsString('/articles/sitemap-review', $response->getContent());
        $this->assertStringNotContainsString('/articles/sitemap-blog', $response->getContent());
        $this->assertStringNotContainsString('/blog/sitemap-review', $response->getContent());
    }

    public function test_llms_txt_splits_reviews_and_blog_posts_into_sections(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'llms-blog']);
        $review = $this->makeReviewArticle('llms-review');

        $response = $this->get('/llms.txt');

        $response->assertOk();
        $this->assertStringContainsString('## مراجعات ومقارنات الأسعار المحدثة', $response->getContent());
        $this->assertStringContainsString('## المدونة والمقالات الإرشادية', $response->getContent());
        $this->assertStringContainsString('/articles/llms-review', $response->getContent());
        $this->assertStringContainsString('/blog/llms-blog', $response->getContent());
        $this->assertStringNotContainsString('/blog/llms-review', $response->getContent());
    }

    public function test_blog_show_renders_markdown_tables_as_html_tables(): void
    {
        $post = $this->makeBlogPost([
            'slug' => 'table-render',
            'content' => "## جدول مقارنة\n\n| الأداة | السعر |\n| :--- | :--- |\n| أمان برايس | مجاني |\n| كان بكام | مجاني |",
        ]);

        $parsed = (string) app(ShortcodeParser::class)->parse($post);

        $this->assertStringContainsString('<table>', $parsed);
        $this->assertStringContainsString('<th', $parsed);
        $this->assertStringContainsString('<td', $parsed);
        $this->assertStringNotContainsString('| :---', $parsed);

        $this->get('/blog/'.$post->slug)
            ->assertOk()
            ->assertSee('<table>', false);
    }

    public function test_markdown_strikethrough_renders_in_article_content(): void
    {
        $post = $this->makeBlogPost([
            'slug' => 'strike-render',
            'content' => 'سعر **500 ج.م** ~~700 ج.م~~ كان.',
        ]);

        $parsed = (string) app(ShortcodeParser::class)->parse($post);

        $this->assertStringContainsString('<del>', $parsed);
    }

    public function test_model_scopes_and_helpers_partition_content(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'model-scope-blog']);
        $review = $this->makeReviewArticle('model-scope-review');

        $this->assertTrue($blogPost->isBlog());
        $this->assertFalse($blogPost->isReview());
        $this->assertTrue($review->isReview());
        $this->assertFalse($review->isBlog());

        $this->assertSame([$blogPost->id], Article::blog()->pluck('id')->all());
        $this->assertContains($review->id, Article::reviews()->pluck('id')->all());
        $this->assertGreaterThanOrEqual(1, $blogPost->readMinutes());
    }
}

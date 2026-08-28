<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Services\SEOHelper;
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

    private function makeProduct(string $asin, string $title, int $price = 8900): Product
    {
        return Product::create([
            'category_id' => $this->makeCategory()->id,
            'title' => $title,
            'asin' => $asin,
            'brand' => 'فريش',
            'price' => $price,
            'original_price' => 9900,
            'affiliate_url' => 'https://www.amazon.eg/dp/'.$asin,
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);
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

    private function makeComparisonArticle(string $slug = 'compare-ac'): Article
    {
        $category = $this->makeCategory('compare-cat', 'تكييفات');

        $first = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف كاريير إكس كول',
            'asin' => 'CMPASIN001',
            'brand' => 'كاريير',
            'price' => 27270,
            'original_price' => 28774,
            'affiliate_url' => 'https://www.amazon.eg/dp/CMPASIN001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        $second = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف ميديا إيكو ماستر',
            'asin' => 'CMPASIN002',
            'brand' => 'ميديا',
            'price' => 23100,
            'original_price' => 25000,
            'affiliate_url' => 'https://www.amazon.eg/dp/CMPASIN002',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        $article = Article::create([
            'category_id' => $category->id,
            'type' => 'review',
            'title' => 'مقارنة بين تكييف كاريير وميديا: أيهما أوفر في الكهرباء؟',
            'slug' => $slug,
            'meta_description' => 'مقارنة شاملة بين جهازين.',
            'content' => "## نظرة عامة\n\nقارن الجهازين قبل الشراء.",
            'is_published' => true,
        ]);

        $article->products()->attach([$first->id, $second->id]);

        return $article;
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

    private function schemaObjects(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        $objects = [];

        foreach ($matches[1] ?? [] as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $objects[] = $decoded;
            }
        }

        return $objects;
    }

    private function schemaByType(string $html, string $type): ?array
    {
        foreach ($this->schemaObjects($html) as $object) {
            if (($object['@type'] ?? null) === $type) {
                return $object;
            }
        }

        return null;
    }

    public function test_home_feed_excludes_blog_posts_from_product_cards(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'home-leak-blog', 'title' => 'بدائل كان بكام في مصر']);
        $review = $this->makeReviewArticle('home-leak-review');
        $review->title = 'مراجعة للتأكد من وجودها';
        $review->save();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee($review->title);
        $response->assertDontSee($blogPost->title);
        $response->assertDontSee('بدائل كان بكام');
    }

    public function test_related_reviews_exclude_blog_posts_from_same_category(): void
    {
        $blogPost = $this->makeBlogPost(['slug' => 'related-leak-blog', 'category_id' => $this->makeCategory()->id]);
        $review = $this->makeReviewArticle('related-leak-review');
        $review->category_id = $blogPost->category_id;
        $review->save();

        $response = $this->get('/articles/'.$review->slug);

        $response->assertOk();
        $response->assertSee($review->title);
        $response->assertDontSee($blogPost->title);
    }

    public function test_home_renders_comparisons_in_dedicated_slider_not_in_product_grid(): void
    {
        $comparison = $this->makeComparisonArticle('home-compare');
        $review = $this->makeReviewArticle('home-compare-review');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee($review->title);
        $response->assertSee('تصفح كل مقارنات', false);
        $response->assertSee('?comparisons=1', false);

        // The comparison renders once — in its dedicated slider (full title), it
        // is never duplicated into the single-product card grid.
        $this->assertSame(1, substr_count($response->getContent(), $comparison->title));
    }

    public function test_comparisons_listing_page_lists_all_comparisons(): void
    {
        $comparison = $this->makeComparisonArticle('list-compare');
        $review = $this->makeReviewArticle('list-compare-review');

        $response = $this->get('/?comparisons=1');

        $response->assertOk();
        $response->assertSee('مقارنة محدثة', false);
        $response->assertSee('المقارنات', false);
        $response->assertSee($comparison->title);
        $response->assertDontSee($review->title);
    }

    public function test_comparison_articles_still_show_in_category_hub_and_search(): void
    {
        $comparison = $this->makeComparisonArticle('hub-compare');
        $review = $this->makeReviewArticle('hub-compare-review');

        $this->get('/category/compare-cat')
            ->assertOk()
            ->assertSee($comparison->title)
            ->assertDontSee($review->title);

        $this->get('/?q='.urlencode('مقارنة'))
            ->assertOk()
            ->assertSee($comparison->title);
    }

    public function test_related_deals_on_review_page_exclude_comparisons(): void
    {
        $comparison = $this->makeComparisonArticle('related-compare');
        $review = $this->makeReviewArticle('related-compare-review');
        $comparison->update(['category_id' => $review->category_id]);

        $response = $this->get('/articles/'.$review->slug);

        $response->assertOk();
        $response->assertDontSee($comparison->title);
    }

    public function test_blog_show_uses_first_content_image_as_og_and_blogposting_image(): void
    {
        $cover = 'https://bucket.r2.dev/articles/art-badael-kanbkam-20260817223618-b585b491.webp';
        $post = $this->makeBlogPost([
            'slug' => 'og-content-image',
            'content' => "![غلاف المقارنة]({$cover})\n\n## تابع القراءة\n\nمحتوى المقال هنا.",
        ]);

        $html = $this->get('/blog/'.$post->slug)->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="'.$cover.'">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="'.$cover.'">', $html);

        $posting = $this->schemaByType($html, 'BlogPosting');
        $this->assertNotNull($posting);
        $this->assertSame([$cover], $posting['image']);

        // The favicon must never leak into social/schema image fields.
        $this->assertStringNotContainsString('og:image" content="'.url('/favicon.svg').'"', $html);
        $this->assertStringNotContainsString('favicon.svg', implode(',', (array) $posting['image']));
    }

    public function test_blog_show_image_falls_back_to_og_placeholder_when_no_content_image(): void
    {
        $post = $this->makeBlogPost(['slug' => 'og-placeholder-image']);

        $html = $this->get('/blog/'.$post->slug)->getContent();

        $expected = SEOHelper::url('img/og-image.png');

        $this->assertStringContainsString('<meta property="og:image" content="'.$expected.'">', $html);

        $posting = $this->schemaByType($html, 'BlogPosting');
        $this->assertNotNull($posting);
        $this->assertSame([$expected], $posting['image']);
    }

    public function test_blog_show_breadcrumb_schema_matches_dom_for_uncategorized_post(): void
    {
        $post = $this->makeBlogPost(['slug' => 'breadcrumb-flat']);

        $html = $this->get('/blog/'.$post->slug)->getContent();

        $breadcrumb = $this->schemaByType($html, 'BreadcrumbList');
        $this->assertNotNull($breadcrumb);

        $items = $breadcrumb['itemListElement'];
        $this->assertCount(3, $items);
        $this->assertSame('الرئيسية', $items[0]['name']);
        $this->assertSame('المدونة', $items[1]['name']);
        $this->assertSame(SEOHelper::canonical('blog'), $items[1]['item']);
        $this->assertSame($post->title, $items[2]['name']);
        $this->assertSame(3, $items[2]['position']);

        // No duplicate "المدونة" node pointing back at the homepage (loop).
        $this->assertStringNotContainsString('"name":"المدونة","@type":"ListItem","position":3,"item":"'.SEOHelper::url().'"', $html);
        $this->assertStringNotContainsString('"name":"المدونة","@type":"ListItem","position":3', $html);
    }

    public function test_blog_show_breadcrumb_schema_adds_category_level_when_dom_has_it(): void
    {
        $post = $this->makeBlogPost([
            'slug' => 'breadcrumb-categorized',
            'category_id' => $this->makeCategory()->id,
        ]);
        $post->refresh();

        $html = $this->get('/blog/'.$post->slug)->getContent();

        $breadcrumb = $this->schemaByType($html, 'BreadcrumbList');
        $this->assertNotNull($breadcrumb);

        $items = $breadcrumb['itemListElement'];
        $this->assertCount(4, $items);
        $this->assertSame('المدونة', $items[1]['name']);
        $this->assertSame($post->category->name, $items[2]['name']);
        $this->assertSame(SEOHelper::canonical('category/'.$post->category->slug), $items[2]['item']);
        $this->assertSame($post->title, $items[3]['name']);
        $this->assertSame(4, $items[3]['position']);
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

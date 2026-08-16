<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownNegotiationTest extends TestCase
{
    use RefreshDatabase;

    private function makePublishedArticle(string $slug = 'md-article'): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'md-cat'], ['name' => 'فئة']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج اختبار',
            'asin' => 'MDASIN0001',
            'brand' => 'فريش',
            'price' => 1000,
            'original_price' => 1200,
            'affiliate_url' => 'https://www.amazon.eg/dp/MDASIN0001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        return Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال تجريبي',
            'slug' => $slug,
            'meta_description' => 'وصف تجريبي للمقال',
            'content' => "## مقدمة\nنص المقال [price] [rating] [installment] [buy_button]",
            'is_published' => true,
        ]);
    }

    public function test_home_serves_markdown_when_agent_requests_it(): void
    {
        $this->makePublishedArticle('md-article');

        $response = $this->get('/', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertSame('Accept', $response->headers->get('Vary'));
        $this->assertStringContainsString('# أمان برايس', $response->getContent());
        $this->assertStringContainsString('/articles/md-article', $response->getContent());
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getContent());
    }

    public function test_article_serves_clean_markdown_without_shortcode_tokens(): void
    {
        $article = $this->makePublishedArticle('md-article');

        $response = $this->get('/articles/'.$article->slug, ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# '.$article->title, $response->getContent());
        $this->assertStringContainsString('## مقدمة', $response->getContent());
        $this->assertStringContainsString('asin: MDASIN0001', $response->getContent());
        $this->assertStringContainsString('price_egp: 1000.00', $response->getContent());
        $this->assertStringContainsString('original_price_egp: 1200.00', $response->getContent());
        $this->assertStringNotContainsString('[price]', $response->getContent());
        $this->assertStringNotContainsString('[rating]', $response->getContent());
        $this->assertStringNotContainsString('[buy_button]', $response->getContent());
    }

    public function test_article_markdown_fills_shortcodes_with_dynamic_live_data(): void
    {
        $this->makePublishedArticle('md-dynamic');

        $response = $this->get('/articles/md-dynamic', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $content = $response->getContent();

        // Frontmatter price must match the body price 100% (same formatting).
        $this->assertStringContainsString('price_egp: 1000.00', $content);

        // [price] -> live EGP figure, footer locked to the same number.
        $this->assertStringContainsString('**1000.00 ج.م** (سعر محدث اليوم)', $content);

        // [installment] -> a computed monthly figure, never an empty label.
        $this->assertStringContainsString('قسط شهري', $content);
        $this->assertStringContainsString('0% فائدة', $content);

        // [buy_button] -> a real affiliate markdown link.
        $this->assertStringContainsString('[🛒 اشترِ الآن بأقل سعر من أمازون مصر](https://www.amazon.eg/dp/MDASIN0001)', $content);

        // The old stripping bug left "**السعر الحالي على أمازون مصر:** " dangling.
        $this->assertStringNotContainsString('**السعر الحالي على أمازون مصر:**', $content);
        $this->assertStringNotContainsString('[rating]', $content);
    }

    public function test_comparison_article_markdown_emits_table_and_ranked_cards(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'md-comp-cat'], ['name' => 'فئة مقارنة']);

        $first = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج أول للمقارنة',
            'asin' => 'MDCOMP0001',
            'brand' => 'فريش',
            'price' => 1500,
            'rating' => 4.5,
            'review_count' => 30,
            'affiliate_url' => 'https://www.amazon.eg/dp/MDCOMP0001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        $second = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج ثانٍ للمقارنة',
            'asin' => 'MDCOMP0002',
            'brand' => 'هاير',
            'price' => 1200,
            'rating' => 3.8,
            'review_count' => 12,
            'affiliate_url' => 'https://www.amazon.eg/dp/MDCOMP0002',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        $article = Article::create([
            'product_id' => null,
            'category_id' => $category->id,
            'title' => 'أفضل منتجين مقارنة',
            'slug' => 'md-comparison',
            'meta_description' => 'مقارنة بين منتجين',
            'content' => "## المقارنة\n\n[comparison_table]\n\n[product_cards]",
            'is_published' => true,
        ]);

        $article->articleProducts()->create(['product_id' => $first->id, 'sort_order' => 1, 'badge_label' => 'الأفضل', 'quick_verdict' => 'ممتاز']);
        $article->articleProducts()->create(['product_id' => $second->id, 'sort_order' => 2, 'badge_label' => 'الأوفر', 'quick_verdict' => 'جيد']);

        $response = $this->get('/articles/md-comparison', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $content = $response->getContent();

        // No shortcode token or empty label survives.
        $this->assertStringNotContainsString('[comparison_table]', $content);
        $this->assertStringNotContainsString('[product_cards]', $content);

        // Markdown table carries both products with their live prices/links.
        $this->assertStringContainsString('| المنتج | السعر الحالي | رابط الشراء |', $content);
        $this->assertStringContainsString('| منتج أول للمقارنة | 1500.00 ج.م | [اشترِ من أمازون](https://www.amazon.eg/dp/MDCOMP0001) |', $content);
        $this->assertStringContainsString('| منتج ثانٍ للمقارنة | 1200.00 ج.م | [اشترِ من أمازون](https://www.amazon.eg/dp/MDCOMP0002) |', $content);

        // Ranked cards.
        $this->assertStringContainsString('#### #1 منتج أول للمقارنة', $content);
        $this->assertStringContainsString('#### #2 منتج ثانٍ للمقارنة', $content);
        $this->assertStringContainsString('4.5/5 (30 مراجعة)', $content);
        $this->assertStringContainsString('ممتاز', $content);
        $this->assertStringContainsString('الأوفر', $content);
    }

    public function test_single_article_markdown_fills_summary_box_with_pros_and_cons(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'md-summary-cat'], ['name' => 'فئة']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج ملخص',
            'asin' => 'MDSUMMRY01',
            'brand' => 'فريش',
            'price' => 1000,
            'original_price' => 1300,
            'rating' => 4.1,
            'review_count' => 24,
            'affiliate_url' => 'https://www.amazon.eg/dp/MDSUMMRY01',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'ملخص منتج',
            'slug' => 'md-summary',
            'content' => "## ملخص\n\n[summary_box]\n\n[price]",
            'is_published' => true,
        ]);

        $response = $this->get('/articles/md-summary', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $content = $response->getContent();

        // [summary_box] -> a Markdown pros/cons/verdict block, never blank.
        $this->assertStringContainsString('### 💡 ملخص التقييم: منتج ملخص', $content);
        $this->assertStringContainsString('- ✅', $content);
        $this->assertStringContainsString('- ❌', $content);
        $this->assertStringContainsString('**الخلاصة والتقييم:**', $content);
        $this->assertStringNotContainsString('[summary_box]', $content);

        // [price] still interpolated alongside.
        $this->assertStringContainsString('**1000.00 ج.م** (سعر محدث اليوم)', $content);
    }

    public function test_browser_without_markdown_accept_still_gets_html(): void
    {
        $article = $this->makePublishedArticle('md-article');

        $response = $this->get('/articles/'.$article->slug);

        $response->assertOk();
        $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
        $this->assertStringNotContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    public function test_unpublished_article_is_not_leaked_as_markdown(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'md-draft-cat'], ['name' => 'فئة']);

        Article::create([
            'category_id' => $category->id,
            'title' => 'مسودة',
            'slug' => 'md-draft',
            'content' => 'مسودة سرية',
            'is_published' => false,
        ]);

        $this->get('/articles/md-draft', ['Accept' => 'text/markdown'])->assertNotFound();
    }

    public function test_markdown_variant_query_param_serves_markdown_without_accept_header(): void
    {
        $this->makePublishedArticle('md-article');

        $response = $this->get('/articles/md-article?_fmt=md');

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# مقال تجريبي', $response->getContent());
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_markdown_variant_request_header_serves_markdown_without_accept_header(): void
    {
        $this->makePublishedArticle('md-article');

        $response = $this->get('/articles/md-article', ['_fmt' => 'md']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# مقال تجريبي', $response->getContent());
    }

    public function test_llms_txt_is_served(): void
    {
        $this->makePublishedArticle('md-llms-article');

        $response = $this->get('/llms.txt');

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# أمان برايس', $response->getContent());
        $this->assertStringContainsString('/articles/md-llms-article', $response->getContent());
    }

    public function test_llms_txt_never_embeds_static_prices(): void
    {
        // Amazon prices change daily — llms.txt must not pin numeric values that
        // would hallucinate stale figures into model answers. Prices belong to
        // the live [price]/[installment] shortcodes at request time.
        $this->makePublishedArticle('md-llms-price');

        $content = $this->get('/llms.txt')->getContent();

        $this->assertStringNotContainsString('ج.م', $content);
        $this->assertStringNotContainsString('1000', $content);
        $this->assertStringNotContainsString('1200', $content);
        $this->assertStringContainsString('/articles/md-llms-price', $content);
    }
}
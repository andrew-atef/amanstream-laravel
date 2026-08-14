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
            'content' => "## مقدمة\nنص المقال [price] [rating] [buy_button]",
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
}
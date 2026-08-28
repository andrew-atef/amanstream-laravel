<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the central Amazon Affiliate URL Normalization Engine:
 * scraped tracking junk (&dib=, &dib_tag=, &crid=, &sprefix=, &qid=, psc ...)
 * must NEVER reach HTML output, Markdown variants, MCP results or schema.org
 * offers — every emitted link is the clean first-party /go/{ASIN} redirect.
 */
class AffiliateUrlNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL = 'https://www.amazon.eg/dp/NORMASIN01?tag=khatfadeals2-21';

    /** First-party cloaked URL that must appear in public DOM/Markdown. */
    private string $goUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goUrl = config('app.url').'/go/NORMASIN01';
    }

    private const MESSY = 'https://www.amazon.eg/dp/NORMASIN01?ref_=ppx_yo_mob_b_populate_td_t2&dib=eyJ2IjoiMSJ9&dib_tag=se&sprefix=%2Caps%2C247&crid=3GK0X2VX&qid=1716110000&psc=1&tag=demo';

    private function makeProduct(): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'norm-cat'], ['name' => 'فئة']);

        return Product::create([
            'category_id' => $category->id,
            'title' => 'منتج للاختبار',
            'asin' => 'NORMASIN01',
            'brand' => 'فريش',
            'price' => 1000,
            'original_price' => 1200,
            'rating' => 4.5,
            'review_count' => 10,
            'affiliate_url' => self::MESSY,
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);
    }

    private function makeArticle(Product $product, string $content): Article
    {
        return Article::create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'title' => 'مقال للاختبار',
            'slug' => 'affiliate-normalization',
            'content' => $content,
            'is_published' => true,
        ]);
    }

    public function test_model_accessor_always_reads_the_clean_canonical_url(): void
    {
        $product = $this->makeProduct();

        $this->assertSame(self::CANONICAL, $product->affiliate_url);
        $this->assertSame(self::CANONICAL, $product->clean_affiliate_url);
        $this->assertStringNotContainsString('sprefix', $product->affiliate_url);
    }

    public function test_article_html_never_emits_scraped_tracking_junk(): void
    {
        $product = $this->makeProduct();
        $article = $this->makeArticle($product, "## شراء\n\n[price]\n\n[buy_button]");

        $html = $this->get('/articles/'.$article->slug)->getContent();

        // The canonical buy-bar CTA (h1 hero + sticky bar) carries the clean /go/ link.
        $this->assertStringContainsString('href="'.$this->goUrl.'"', $html);
        $this->assertStringContainsString($this->goUrl, $html);
        $this->assertStringNotContainsString('&dib=', $html);
        $this->assertStringNotContainsString('&crid=', $html);
        $this->assertStringNotContainsString('&sprefix=', $html);
        $this->assertStringNotContainsString('&qid=', $html);
        $this->assertStringNotContainsString('&psc=', $html);
    }

    public function test_article_markdown_offer_url_and_ctas_are_clean(): void
    {
        $product = $this->makeProduct();
        $article = $this->makeArticle($product, "## شراء\n\n[price]\n\n[buy_button]");

        $content = $this->get('/articles/'.$article->slug, ['Accept' => 'text/markdown'])->getContent();

        $this->assertStringContainsString('offer_url: '.$this->goUrl, $content);
        $this->assertStringContainsString($this->goUrl, $content);
        $this->assertStringNotContainsString('&dib=', $content);
        $this->assertStringNotContainsString('&sprefix=', $content);
        $this->assertStringNotContainsString('&crid=', $content);
        $this->assertStringNotContainsString('&qid=', $content);
        $this->assertStringNotContainsString('&psc=', $content);
    }

    public function test_mcp_search_results_use_clean_affiliate_links(): void
    {
        $this->makeProduct();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'منتج للاختبار'],
            ],
        ]);

        $response->assertOk();
        $text = $response->json('result.content.0.text');

        $this->assertStringContainsString('**رابط الشراء المباشر:** '.$this->goUrl, $text);
        $this->assertStringContainsString(']('.$this->goUrl.')', $text);
        $this->assertStringNotContainsString('&dib=', $text);
        $this->assertStringNotContainsString('&sprefix=', $text);
        $this->assertStringNotContainsString('&crid=', $text);
        $this->assertStringNotContainsString('&qid=', $text);
        $this->assertStringNotContainsString('&psc=', $text);
    }

    public function test_product_head_meta_and_enhanced_merchant_schema_are_emitted(): void
    {
        $product = $this->makeProduct();
        $article = $this->makeArticle($product, "## شراء\n\n[price]");

        $html = $this->get('/articles/'.$article->slug)->getContent();

        // Facebook / product e-commerce meta tags pushed into <head>.
        $this->assertStringContainsString('property="product:price:amount" content="1000.00"', $html);
        $this->assertStringContainsString('property="product:price:currency" content="EGP"', $html);
        $this->assertStringContainsString('property="product:availability" content="in stock"', $html);
        $this->assertStringContainsString('property="product:condition" content="new"', $html);
        $this->assertStringContainsString('property="product:retailer" content="Amazon Egypt"', $html);
        $this->assertStringContainsString('property="product:brand" content="فريش"', $html);
        $this->assertStringContainsString('property="product:retailer_item_id" content="NORMASIN01"', $html);

        // Google Merchant schema: clean offers.url via /go/ redirect, 7-day freshness, return policy, shipping.
        $this->assertStringContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"url":"'.$this->goUrl.'"', $html);
        $this->assertStringContainsString('"priceValidUntil":"'.now()->addDays(7)->format('Y-m-d').'"', $html);
        $this->assertStringContainsString('"hasMerchantReturnPolicy"', $html);
        $this->assertStringContainsString('"merchantReturnDays":14', $html);
        $this->assertStringContainsString('"returnPolicyCategory":"https://schema.org/MerchantReturnFiniteReturnWindow"', $html);
        $this->assertStringContainsString('"returnFees":"https://schema.org/FreeReturn"', $html);
        $this->assertStringContainsString('"shippingDetails"', $html);
        $this->assertStringContainsString('"addressCountry":"EG"', $html);

        // No scraped junk anywhere in the rendered page.
        $this->assertStringNotContainsString('&dib=', $html);
        $this->assertStringNotContainsString('&sprefix=', $html);
        $this->assertStringNotContainsString('&crid=', $html);
        $this->assertStringNotContainsString('&qid=', $html);
        $this->assertStringNotContainsString('&psc=', $html);
    }
}

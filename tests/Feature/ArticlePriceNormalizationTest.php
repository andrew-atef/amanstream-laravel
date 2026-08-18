<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePriceNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(float $price = 1479.0): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'price-cat'], ['name' => 'فئة']);

        return Product::create([
            'category_id' => $category->id,
            'title' => 'منتج سعر',
            'asin' => 'PRICE00001',
            'price' => $price,
            'affiliate_url' => 'https://www.amazon.eg/dp/PRICE00001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);
    }

    public function test_hardcoded_egp_prices_in_prose_are_rewritten_to_price_shortcode(): void
    {
        $product = $this->makeProduct();

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'title' => 'مروحة',
            'slug' => 'fan-price',
            'content' => "## مقدمة\n\nبتكلفة اقتصادية تلامس 1,475 جنيهاً مصرياً.\nحول 1,475 ج.م لشرائها.",
            'is_published' => true,
        ]);

        // Double-check the mutator normalized the old figures before persisting.
        $article->refresh();

        $this->assertStringNotContainsString('1,475', $article->content);
        $this->assertStringContainsString('[price]', $article->content);
        $this->assertStringContainsString('بتكلفة اقتصادية تلامس [price].', $article->content);
        $this->assertStringContainsString('حول [price] لشرائها.', $article->content);
    }

    public function test_real_product_specs_numbers_are_not_touched(): void
    {
        $product = $this->makeProduct();

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'title' => 'مروحة',
            'slug' => 'spec-numbers',
            'content' => "## مقدمة\n\nقوة 1.5 حصان و 284 مراجعة حقيقية، ويشمل 3 إعدادات سرعة.",
            'is_published' => true,
        ]);

        $this->assertStringNotContainsString('[price]', $article->content);
        $this->assertStringContainsString('1.5 حصان', $article->content);
        $this->assertStringContainsString('284 مراجعة', $article->content);
    }

    public function test_rewrite_applies_to_comparison_article_content_too(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'price-cat2'], ['name' => 'فئة']);

        $first = Product::create([
            'category_id' => $category->id,
            'title' => 'أول',
            'asin' => 'PRICE00002',
            'price' => 1500,
            'affiliate_url' => 'https://www.amazon.eg/dp/PRICE00002',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        $article = Article::create([
            'category_id' => $category->id,
            'title' => 'مقارنة',
            'slug' => 'price-compare',
            'content' => "## المقارنة\n\nالأول سعره 1,475 جنيه فقط.\n\n[comparison_table]",
            'is_published' => true,
        ]);

        $article->articleProducts()->create(['product_id' => $first->id, 'sort_order' => 1]);

        $article->refresh();

        $this->assertStringNotContainsString('1,475', $article->content);
        $this->assertStringContainsString('الأول سعره [price] فقط.', $article->content);
        $this->assertStringContainsString('[comparison_table]', $article->content);
    }

    public function test_localhost_url_never_double_slashes_in_markdown_frontmatter(): void
    {
        $product = $this->makeProduct();

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'title' => 'مروحة سعرية',
            'slug' => 'price-url',
            'content' => "## مقدمة\n\nالسعر [price] الآن.",
            'is_published' => true,
        ]);

        $response = $this->get('/articles/price-url', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $content = $response->getContent();

        // url frontmatter must be a single clean join — no "//" anywhere.
        $this->assertStringNotContainsString('//', str_replace(['http://', 'https://'], '', $content));
        $this->assertStringContainsString('url: '.config('app.url').'/articles/price-url', $content);
        $this->assertStringContainsString('**1479.00 ج.م** (سعر محدث اليوم — [التحقق من السعر والضمان على أمازون مصر](https://www.amazon.eg/dp/PRICE00001?tag=khatfadeals2-21))', $content);
    }
}

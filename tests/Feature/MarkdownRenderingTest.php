<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MarkdownRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function seedMarkdownArticle(): Article
    {
        $category = Category::create([
            'name' => 'تكييفات',
            'slug' => 'air-conditioners',
            'description' => 'أجهزة التكييف',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'تكييف فريش 1.5 حصان',
            'asin' => 'B0MARKDOWN',
            'brand' => 'Fresh',
            'price' => 18521.00,
            'rating' => 4.5,
            'review_count' => 120,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0MARKDOWN?tag=demo-21',
            'in_stock' => true,
        ]);

        return Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال مارك داون',
            'slug' => 'markdown-article',
            'content' => <<<'MD'
## لماذا هذا التكييف؟

نص **غامق** و *مائل* ضمن الفقرة.

- خاصية التبريد التربو السريع
- تشغيل هادئ جداً

[price]

[installment]
MD,
            'is_published' => true,
        ]);
    }

    #[Test]
    public function it_renders_markdown_into_html(): void
    {
        $article = $this->seedMarkdownArticle();

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringContainsString('<h2>لماذا هذا التكييف؟</h2>', $html);
        $this->assertStringContainsString('<strong>غامق</strong>', $html);
        $this->assertStringContainsString('<em>مائل</em>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>خاصية التبريد التربو السريع</li>', $html);
    }

    #[Test]
    public function it_still_replaces_shortcodes_after_markdown_conversion(): void
    {
        $article = $this->seedMarkdownArticle();

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringNotContainsString('[price]', $html);
        $this->assertStringNotContainsString('[installment]', $html);
        $this->assertStringContainsString('bg-emerald-50', $html);
        $this->assertStringContainsString('قسّط سعر الجهاز على 12 شهر', $html);
        $this->assertStringContainsString('18,521.00 ج.م', $html);
    }

    #[Test]
    public function it_renders_the_rating_widget_in_arabic(): void
    {
        $category = Category::create(['name' => 'أجهزة', 'slug' => 'devices']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج عليه تقييمات',
            'asin' => 'B0RATING1',
            'brand' => 'Demo',
            'price' => 100.00,
            'rating' => 4.5,
            'review_count' => 87,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0RATING1',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال تقييم',
            'slug' => 'rating-article',
            'content' => '[rating]',
            'is_published' => true,
        ]);

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringContainsString('4.5 من 5', $html);
        $this->assertStringContainsString('(87 مراجعة)', $html);
        $this->assertStringNotContainsString('built on', $html);
        $this->assertStringNotContainsString('(4.5 / 5', $html);
    }

    #[Test]
    public function it_renders_the_rating_widget_with_no_reviews_in_arabic(): void
    {
        $category = Category::create(['name' => 'أجهزة', 'slug' => 'devices']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج بلا تقييمات',
            'asin' => 'B0RATING0',
            'brand' => 'Demo',
            'price' => 100.00,
            'rating' => 0,
            'review_count' => 0,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0RATING0',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال بدون تقييم',
            'slug' => 'rating-article-none',
            'content' => '[rating]',
            'is_published' => true,
        ]);

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringContainsString('0.0 من 5', $html);
        $this->assertStringContainsString('لا توجد مراجعات بعد', $html);
        $this->assertStringNotContainsString('built on 0', $html);
    }

    #[Test]
    public function it_passes_existing_html_through_unchanged(): void
    {
        $category = Category::create(['name' => 'أجهزة', 'slug' => 'devices']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'B0HTML0001',
            'brand' => 'Demo',
            'price' => 100.00,
            'rating' => 4.0,
            'review_count' => 5,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0HTML0001',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال HTML',
            'slug' => 'html-article',
            'content' => '<p>فقرة <strong>HTML</strong> مباشرة</p><h2>عنوان من HTML</h2>',
            'is_published' => true,
        ]);

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringContainsString('<p>فقرة <strong>HTML</strong> مباشرة</p>', $html);
        $this->assertStringContainsString('<h2>عنوان من HTML</h2>', $html);
    }
}

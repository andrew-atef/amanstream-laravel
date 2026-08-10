<?php

namespace Tests\Feature;

use App\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleResourceLinksRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_form_renders_product_links_for_selected_product(): void
    {
        $category = Category::create([
            'name' => 'تكييفات',
            'slug' => 'air-conditioners',
            'description' => 'أجهزة التكييف',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج تجريبي',
            'asin' => 'TESTASIN1',
            'price' => 100,
            'affiliate_url' => 'https://www.amazon.eg/dp/TESTASIN1?tag=demo',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'category_id' => $category->id,
            'product_id' => $product->id,
            'title' => 'مقال تجريبي',
            'slug' => 'test-article-links',
            'content' => 'body',
            'is_published' => true,
        ]);

        $html = Livewire::test(EditArticle::class, ['record' => $article->id])
            ->html();

        $this->assertStringContainsString('روابط المنتج', $html);
        $this->assertStringContainsString('فتح في أمازون', $html);
        $this->assertStringContainsString('تعديل المنتج هنا', $html);
        $this->assertStringContainsString('معاينة المقال', $html);
        $this->assertStringContainsString('https://www.amazon.eg/dp/TESTASIN1?tag=demo', $html);
        $this->assertMatchesRegularExpression('/<a\s+href="https:\/\/www\.amazon\.eg\/dp\/TESTASIN1\?tag=demo"/', $html);
        $this->assertMatchesRegularExpression('/<a\s+href="[^"]*\/admin\/products\/[0-9]+\/edit"/', $html);
    }

    public function test_create_form_renders_without_links_when_no_product_selected(): void
    {
        $html = Livewire::test(CreateArticle::class)
            ->html();

        $this->assertStringContainsString('روابط المنتج', $html);
        $this->assertStringNotContainsString('فتح في أمازون', $html);
        $this->assertStringContainsString('اختر منتجاً لعرض روابطه', $html);
    }
}
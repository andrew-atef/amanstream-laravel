<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Services\SEOHelper;
use App\Services\ShortcodeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EditorialEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private function seedCategoryAndProduct(array $productOverrides = []): array
    {
        $category = Category::create([
            'name' => 'تكييفات',
            'slug' => 'air-conditioners',
            'description' => 'أجهزة التكييف',
        ]);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'title' => 'تكييف فريش 1.5 حصان',
            'asin' => 'B0TEST00000',
            'brand' => 'Fresh',
            'price' => 15000.00,
            'rating' => 4.5,
            'review_count' => 12,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0TEST00000?tag=demo-21',
            'image_url' => 'https://r2.example.com/fresh-ac.jpg',
            'in_stock' => true,
        ], $productOverrides));

        return [$category, $product];
    }

    private function makeReviewArticle(array $attributes = [], array $productOverrides = []): Article
    {
        [$category, $product] = $this->seedCategoryAndProduct($productOverrides);

        return Article::create(array_merge([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'type' => 'review',
            'title' => 'أفضل تكييفات [year] لشقق مصر',
            'slug' => 'best-ac-'.now()->timestamp,
            'content' => 'دليل شراء [year] و%%year%% و{year}',
            'is_published' => true,
        ], $attributes));
    }

    #[Test]
    public function article_reads_render_the_year_but_database_keeps_the_token(): void
    {
        $article = $this->makeReviewArticle();

        $this->assertSame('أفضل تكييفات '.date('Y').' لشقق مصر', $article->title);
        $this->assertStringContainsString('[year]', $article->getRawOriginal('title'));
        $this->assertStringNotContainsString(date('Y'), $article->getRawOriginal('title'));
        $this->assertStringContainsString('[year]', Article::find($article->id)->getRawOriginal('title'));
    }

    #[Test]
    public function meta_title_and_meta_description_render_year_tokens_on_read(): void
    {
        $article = $this->makeReviewArticle([
            'meta_title' => 'دليل شراء التكييفات [year]',
            'meta_description' => 'أفضل أنواع التكييفات في مصر للعام %%year%%',
        ]);

        $article = Article::find($article->id);

        $this->assertSame('دليل شراء التكييفات '.date('Y'), $article->meta_title);
        $this->assertSame('أفضل أنواع التكييفات في مصر للعام '.date('Y'), $article->meta_description);
        $this->assertStringContainsString('[year]', $article->getRawOriginal('meta_title'));
        $this->assertStringContainsString('%%year%%', $article->getRawOriginal('meta_description'));
        $this->assertStringNotContainsString(date('Y'), $article->getRawOriginal('meta_title'));
    }

    #[Test]
    public function updating_the_title_also_preserves_the_evergreen_token(): void
    {
        $article = $this->makeReviewArticle();
        $article->update(['title' => 'أفضل شاشات [year] للمنزل']);

        $fresh = Article::find($article->id);

        $this->assertSame('أفضل شاشات '.date('Y').' للمنزل', $fresh->title);
        $this->assertStringContainsString('[year]', $fresh->getRawOriginal('title'));
    }

    #[Test]
    public function title_years_render_in_the_article_h1_and_page_title(): void
    {
        $article = $this->makeReviewArticle();

        $html = $this->get('/articles/'.$article->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="text-3xl font-black leading-snug text-ink sm:text-4xl">أفضل تكييفات '.date('Y').' لشقق مصر</h1>', $html);
        $this->assertStringContainsString('<title>أفضل تكييفات '.date('Y').' لشقق مصر</title>', $html);
        $this->assertStringNotContainsString('[year]', $html);
    }

    #[Test]
    public function shortcode_parser_renders_year_tokens_in_html_and_markdown(): void
    {
        $article = $this->makeReviewArticle(['content' => '<p>دليل [year] —— %%year%% —— {year}</p>']);

        $html = (new ShortcodeParser)->parse($article)->toHtml();
        $this->assertStringContainsString('دليل '.date('Y'), $html);
        $this->assertStringNotContainsString('[year]', $html);
        $this->assertStringNotContainsString('%%year%%', $html);
        $this->assertStringNotContainsString('{year}', $html);

        $markdown = (new ShortcodeParser)->parseForMarkdown($article);
        $this->assertStringContainsString(date('Y'), $markdown);
        $this->assertStringNotContainsString('[year]', $markdown);
        $this->assertStringNotContainsString('%%year%%', $markdown);
        $this->assertStringNotContainsString('{year}', $markdown);
    }

    #[Test]
    public function primary_image_prefers_the_custom_featured_cover(): void
    {
        $article = $this->makeReviewArticle(['featured_image_url' => 'https://r2.example.com/cover.png']);

        $this->assertSame('https://r2.example.com/cover.png', $article->primary_image_url);
    }

    #[Test]
    public function primary_image_falls_back_to_product_then_comparison_then_favicon(): void
    {
        $withProduct = $this->makeReviewArticle();
        $this->assertSame('https://r2.example.com/fresh-ac.jpg', $withProduct->primary_image_url);

        // Product without an image: falls through to the first compared product.
        $coolCategory = Category::create([
            'name' => 'مبردات',
            'slug' => 'coolers',
            'description' => 'أجهزة التبريد',
        ]);
        $compared = Product::create([
            'category_id' => $coolCategory->id,
            'title' => 'مكيف بارد بدون صورة',
            'asin' => 'B0NOIMG0001',
            'brand' => 'Value',
            'price' => 12000.00,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0NOIMG0001',
            'image_url' => 'https://r2.example.com/compared.jpg',
            'in_stock' => true,
        ]);

        $comparison = Article::create([
            'product_id' => null,
            'category_id' => $coolCategory->id,
            'type' => 'review',
            'title' => 'أفضل مكيفات [year] في مصر',
            'slug' => 'best-coolers-'.now()->timestamp,
            'content' => 'مقارنة',
            'is_published' => true,
        ]);
        $comparison->articleProducts()->create([
            'product_id' => $compared->id,
            'sort_order' => 1,
        ]);

        $this->assertSame('https://r2.example.com/compared.jpg', $comparison->primary_image_url);

        // No image anywhere: the brand favicon is the last-resort fallback.
        $imageless = Article::create([
            'product_id' => null,
            'category_id' => $coolCategory->id,
            'type' => 'blog',
            'title' => 'دليل عام بدون صور',
            'slug' => 'guide-'.now()->timestamp,
            'content' => 'لا صور هنا',
            'is_published' => true,
        ]);
        $this->assertSame(SEOHelper::url('favicon.svg'), $imageless->primary_image_url);
    }

    #[Test]
    public function featured_cover_drives_og_twitter_and_the_hero_banner(): void
    {
        $article = $this->makeReviewArticle(['featured_image_url' => 'https://r2.example.com/cover.png']);

        $html = $this->get('/articles/'.$article->slug)->assertOk()->getContent();

        $this->assertStringContainsString('property="og:image" content="https://r2.example.com/cover.png"', $html);
        $this->assertStringContainsString('name="twitter:image" content="https://r2.example.com/cover.png"', $html);
        $this->assertStringContainsString('src="https://r2.example.com/cover.png"', $html);
        $this->assertStringContainsString('aspect-[16/9] w-full object-cover', $html);
    }

    #[Test]
    public function article_show_never_emits_the_favicon_as_an_og_image(): void
    {
        $noImage = $this->makeReviewArticle([], ['image_url' => null]);

        $html = $this->get('/articles/'.$noImage->slug)->assertOk()->getContent();

        $this->assertStringContainsString('property="og:image" content="'.url('/img/og-image.png').'"', $html);
        $this->assertStringNotContainsString('og:image" content="'.url('/favicon.svg').'"', $html);
        $this->assertStringNotContainsString('src="'.SEOHelper::url('favicon.svg').'"', $html);
    }

    #[Test]
    public function product_cards_prefer_the_featured_cover_over_the_product_image(): void
    {
        $this->makeReviewArticle(['featured_image_url' => 'https://r2.example.com/card-cover.png']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('src="https://r2.example.com/card-cover.png"', $html);
        $this->assertStringNotContainsString('src="https://r2.example.com/fresh-ac.jpg"', $html);
        $this->assertStringNotContainsString('لا توجد صورة', $html);
    }

    #[Test]
    public function blog_index_and_blog_show_use_the_featured_cover_when_set(): void
    {
        $slug = 'kitchen-guide-'.now()->timestamp;

        Article::create([
            'product_id' => null,
            'category_id' => null,
            'type' => 'blog',
            'title' => 'دليل المطبخ [year]',
            'slug' => $slug,
            'content' => 'محتوى إرشادي',
            'featured_image_url' => 'https://r2.example.com/blog-cover.png',
            'is_published' => true,
        ]);

        $index = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('src="https://r2.example.com/blog-cover.png"', $index);

        $show = $this->get('/blog/'.$slug)->assertOk()->getContent();
        $this->assertStringContainsString('property="og:image" content="https://r2.example.com/blog-cover.png"', $show);
    }

    #[Test]
    public function blog_index_keeps_the_gradient_tile_when_no_cover_exists(): void
    {
        Article::create([
            'product_id' => null,
            'category_id' => null,
            'type' => 'blog',
            'title' => 'نصائح عامة',
            'slug' => 'tips-'.now()->timestamp,
            'content' => 'محتوى',
            'is_published' => true,
        ]);

        $html = $this->get('/blog')->assertOk()->getContent();

        $this->assertStringContainsString('bg-gradient-to-br', $html);
        $this->assertStringContainsString('drop-shadow-sm', $html);
        $this->assertStringNotContainsString(SEOHelper::url('favicon.svg'), $html);
    }
}

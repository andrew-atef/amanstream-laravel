<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HomePageDynamicsTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): void
    {
        $air = Category::create(['name' => 'تكييفات', 'slug' => 'air-conditioners']);
        $tv = Category::create(['name' => 'شاشات', 'slug' => 'tvs']);

        $ac = Product::create([
            'category_id' => $air->id,
            'title' => 'تكييف فريش',
            'asin' => 'B0AC000000',
            'brand' => 'Fresh',
            'price' => 18521.00,
            'rating' => 4.2,
            'review_count' => 100,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0AC000000',
            'in_stock' => true,
        ]);
        $tvProd = Product::create([
            'category_id' => $tv->id,
            'title' => 'شاشة LG سمارت',
            'asin' => 'B0TV000000',
            'brand' => 'LG',
            'price' => 15000.00,
            'rating' => 4.8,
            'review_count' => 200,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0TV000000',
            'in_stock' => true,
        ]);

        Article::create([
            'product_id' => $ac->id,
            'category_id' => $air->id,
            'title' => 'سعر تكييف فريش 1.5 حصان',
            'slug' => 'fresh-ac',
            'content' => 'ميزة التبريد **التربو**',
            'is_published' => true,
        ]);
        Article::create([
            'product_id' => $ac->id,
            'category_id' => $air->id,
            'title' => 'أفضل تكييف بارد للمساحات الصغيرة',
            'slug' => 'best-small-ac',
            'content' => 'محتوى ثانٍ',
            'is_published' => true,
        ]);
        // Unpublished article must NOT be counted.
        Article::create([
            'product_id' => $ac->id,
            'category_id' => $air->id,
            'title' => 'مسودة غير منشورة',
            'slug' => 'draft',
            'content' => 'مسودة',
            'is_published' => false,
        ]);
        Article::create([
            'product_id' => $tvProd->id,
            'category_id' => $tv->id,
            'title' => 'مراجعة شاشة LG سمارت',
            'slug' => 'lg-tv',
            'content' => 'محتوى الشاشة',
            'is_published' => true,
        ]);
    }

    #[Test]
    public function it_lists_categories_with_real_published_counts_only(): void
    {
        $this->seedCatalog();

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('تكييفات', $html);
        $this->assertStringContainsString('شاشات', $html);
        // "جميع المراجعات" total = 3 published.
        $this->assertStringContainsString('جميع المراجعات', $html);
        $this->assertStringContainsString('تم العثور على <strong>3</strong>', $html);
    }

    #[Test]
    public function it_searches_by_product_asin_and_content(): void
    {
        $this->seedCatalog();

        $byAsin = $this->get('/?q=B0TV000000')->assertOk();
        $this->assertStringContainsString('مراجعة شاشة LG سمارت', $byAsin->getContent());

        $byContent = $this->get('/?q='.rawurlencode('التربو'))->assertOk();
        $this->assertStringContainsString('سعر تكييف فريش 1.5 حصان', $byContent->getContent());
    }

    #[Test]
    public function it_filters_by_category_and_shows_active_filter_bar(): void
    {
        $this->seedCatalog();

        $html = $this->get('/?category=air-conditioners')->getContent();

        $this->assertStringContainsString('التصفية النشطة', $html);
        $this->assertStringContainsString('الفئة: تكييفات', $html);
        $this->assertStringContainsString('إلغاء التصفية', $html);
        // TV article must be absent when filtering the AC category.
        $this->assertStringNotContainsString('مراجعة شاشة LG سمارت', $html);
        // Legacy faceted URLs stay noindex (near-duplicate of the homepage).
        $this->assertStringContainsString('noindex', $html);
    }

    #[Test]
    public function it_serves_clean_indexable_category_hub_pages(): void
    {
        $this->seedCatalog();

        // The clean /category/{slug} destination replaces the ?category= faceted
        // links everywhere and must be an indexable Category Hub.
        $response = $this->get('/category/air-conditioners');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('تكييفات', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('/category/air-conditioners', $html);
        // Category hubs must carry a unique, keyword-targeted <title> and a
        // clean meta description — not the generic homepage fallback.
        $this->assertStringContainsString('<title>سعر تكييفات وأفضل أنواعه في مصر 2025', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('name="description" content="', $html);
        // An ItemList of the hub's top articles lets the hub surface as a
        // structured collection inside the SERP.
        $this->assertStringContainsString('"@type":"ItemList"', $html);
        // TV article must be absent on the AC category hub.
        $this->assertStringNotContainsString('مراجعة شاشة LG سمارت', $html);
        // And the AC articles must be present.
        $this->assertStringContainsString('سعر تكييف فريش 1.5 حصان', $html);
        $this->assertStringContainsString('أفضل تكييف بارد للمساحات الصغيرة', $html);
    }

    #[Test]
    public function it_links_categories_to_clean_hub_urls_in_navigation(): void
    {
        $this->seedCatalog();

        $html = $this->get('/')->getContent();

        // Category links must point to the clean /category/{slug} hubs, not the
        // old faceted ?category= query strings.
        $this->assertStringContainsString('href="'.route('categories.show', 'air-conditioners').'"', $html);
        $this->assertStringContainsString('href="'.route('categories.show', 'tvs').'"', $html);
    }

    #[Test]
    public function it_points_trending_pills_to_category_hubs_or_search(): void
    {
        $this->seedCatalog();

        $html = $this->get('/')->getContent();

        // Pill anchors render attributes in href-then-class order, which lets
        // us assert on the pill specifically (header/sidebar link the same hubs).
        $pillSuffix = '" class="rounded-lg bg-white/10';

        // تكييفات/شاشات exist as sections, so those pills resolve to their
        // category hubs — not to a search query.
        $this->assertStringContainsString('href="'.route('categories.show', 'air-conditioners').$pillSuffix, $html);
        $this->assertStringContainsString('href="'.route('categories.show', 'tvs').$pillSuffix, $html);

        // غسالات/فريش have no category section, so those pills fall back to
        // live search instead of dead-end hubs.
        $this->assertStringContainsString('href="'.route('home', ['q' => 'غسالة']).$pillSuffix, $html);
        $this->assertStringContainsString('href="'.route('home', ['q' => 'فريش']).$pillSuffix, $html);
    }

    #[Test]
    public function it_shows_search_chip_and_clear_bar_when_searching(): void
    {
        $this->seedCatalog();

        $html = $this->get('/?q='.rawurlencode('فريش'))->getContent();

        $this->assertStringContainsString('التصفية النشطة', $html);
        $this->assertStringContainsString('البحث: "فريش"', $html);
        $this->assertStringContainsString('إلغاء التصفية', $html);
        $this->assertStringContainsString('سعر تكييف فريش 1.5 حصان', $html);
        $this->assertStringNotContainsString('مراجعة شاشة LG سمارت', $html);
    }

    #[Test]
    public function it_shows_strikethrough_and_discount_when_original_price_is_set(): void
    {
        $this->seedCatalog();

        Product::where('asin', 'B0AC000000')->update(['original_price' => 22000.00]);

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('22,000', $html);
        $this->assertStringContainsString('line-through', $html);
        $this->assertStringContainsString('-16%', $html);
    }

    #[Test]
    public function pagination_uses_aria_without_the_forbidden_aria_disabled_on_plain_spans(): void
    {
        $this->seedCatalog();

        // Push past the 12-per-page limit so a real multi-page paginator
        // renders, including a disabled Prev placeholder on page one.
        $ac = Product::where('asin', 'B0AC000000')->firstOrFail();
        foreach (range(1, 13) as $i) {
            Article::create([
                'product_id' => $ac->id,
                'category_id' => $ac->category_id,
                'title' => "مقال إضافي $i",
                'slug' => "extra-article-$i",
                'content' => 'محتوى',
                'is_published' => true,
            ]);
        }

        $html = $this->get('/')->getContent();

        // The fix: `aria-disabled` is not a permitted attribute on a role-less
        // <span>. Laravel ships it in the default pagination view, which makes
        // Lighthouse's "elements must only use permitted ARIA attributes" audit
        // fail and distorts the accessibility tree that AI agents read.
        $this->assertStringNotContainsString('aria-disabled', $html);
        // Interaction state is still exposed to assistive tech via the nav's
        // aria-label and the active page's aria-current.
        $this->assertStringContainsString('aria-label="التنقل بين الصفحات"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('rel="next"', $html);
        // The results counter and nav labels render in Arabic for the RTL site.
        $this->assertStringContainsString('نتيجة', $html);
        $this->assertStringContainsString('السابق', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\BulkImportProducts;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkImportAndSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'x-catalog-sync-token-2026';

    public function test_pending_sync_exposes_scrape_reviews_flag_when_empty(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'flag-cat'], ['name' => 'فئة']);
        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'FLAGASIN01',
            'price' => 100,
            'affiliate_url' => 'https://www.amazon.eg/dp/FLAGASIN01',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=5', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $this->assertTrue($response->json()[0]['scrape_reviews']);
    }

    public function test_sync_results_persists_reviews_only_when_originally_blank(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'rvw-cat'], ['name' => 'فئة']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'RVWASIN001',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/RVWASIN001',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1100,
                'in_stock' => true,
                'sync_status' => 'success',
                'raw_reviews_text' => "المنتج ممتاز\nسعر مناسب",
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();
        $this->assertNotNull($product->raw_reviews_text);
        $this->assertNotNull($product->reviews_scraped_at);
    }

    public function test_sync_results_does_not_overwrite_existing_reviews(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'keep-cat'], ['name' => 'فئة']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'KEEPASIN01',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/KEEPASIN01',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
            'raw_reviews_text' => 'نص محفوظ بالفعل',
        ]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1100,
                'in_stock' => true,
                'sync_status' => 'success',
                'raw_reviews_text' => 'نص جديد',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();
        $this->assertEquals('نص محفوظ بالفعل', $product->raw_reviews_text);
        $this->assertNull($product->reviews_scraped_at);
    }

    public function test_product_category_change_syncs_linked_articles(): void
    {
        $catA = Category::query()->firstOrCreate(['slug' => 'cat-a'], ['name' => 'أ']);
        $catB = Category::query()->firstOrCreate(['slug' => 'cat-b'], ['name' => 'ب']);

        $product = Product::create([
            'category_id' => $catA->id,
            'title' => 'منتج',
            'asin' => 'SYNCASIN01',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/SYNCASIN01',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        Article::create([
            'product_id' => $product->id,
            'category_id' => $catA->id,
            'title' => 'مقال',
            'slug' => 'sync-article-'.uniqid(),
            'content' => '<p>محتوى</p>',
            'is_published' => true,
        ]);

        $product->category_id = $catB->id;
        $product->save();

        $this->assertSame($catB->id, $product->articles()->first()->category_id);
    }

    public function test_it_extracts_asins_and_creates_draft_product_and_article(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'bulk-cat'], ['name' => 'فئة']);

        $page = new class extends BulkImportProducts
        {
            public function callExtractAsin(string $url): string
            {
                return $this->extractAsin($url);
            }

            public function uriKey(): string
            {
                return 'bulk-import-products';
            }
        };

        $this->assertEquals('B0ABC123DE', $page->callExtractAsin('https://www.amazon.eg/dp/B0ABC123DE'));
        $this->assertEquals('B0ABC123DE', $page->callExtractAsin('https://www.amazon.com/gp/product/B0ABC123DE/ref=xyz'));
    }

    public function test_bulk_import_refuses_asins_that_already_exist(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'refuse-cat'], ['name' => 'فئة']);

        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج قديم',
            'asin' => 'B0ABC123DE',
            'price' => 100,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0ABC123DE',
            'in_stock' => true,
            'is_active' => false,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $page = new class extends BulkImportProducts
        {
            public function uriKey(): string
            {
                return 'bulk-import-products';
            }
        };

        $result = $page->createProductsFromUrls([
            'https://www.amazon.eg/dp/B0ABC123DE',
            'https://www.amazon.eg/dp/B0NEW12345',
        ], (int) $category->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['B0ABC123DE'], $result['skipped']);

        $this->assertSame(2, Product::query()->count());
        $this->assertSame(1, Article::query()->count());

        $existing = Product::query()->where('asin', 'B0ABC123DE')->first();
        $this->assertSame('منتج قديم', $existing->title);
        $this->assertSame(0, $existing->articles()->count());
    }

    public function test_bulk_import_is_case_insensitive_when_refusing(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'case-cat'], ['name' => 'فئة']);

        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'B0ABC123DE',
            'price' => 100,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0ABC123DE',
            'in_stock' => true,
            'is_active' => false,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);

        $page = new class extends BulkImportProducts
        {
            public function uriKey(): string
            {
                return 'bulk-import-products';
            }
        };

        $result = $page->createProductsFromUrls([
            'https://www.amazon.eg/dp/b0abc123de',
        ], (int) $category->id);

        $this->assertSame(0, $result['created']);
        $this->assertSame(['B0ABC123DE'], $result['skipped']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, Article::query()->count());
    }

    public function test_bulk_import_skips_duplicate_urls_within_the_same_batch(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'dupe-cat'], ['name' => 'فئة']);

        $page = new class extends BulkImportProducts
        {
            public function uriKey(): string
            {
                return 'bulk-import-products';
            }
        };

        $result = $page->createProductsFromUrls([
            'https://www.amazon.eg/dp/B0NEW12345',
            'https://www.amazon.eg/dp/b0new12345',
        ], (int) $category->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, Article::query()->count());
    }
}

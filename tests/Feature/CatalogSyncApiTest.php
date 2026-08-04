<?php

namespace Tests\Feature;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogSyncApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'x-catalog-sync-token-2026';

    private function makeCategory(): Category
    {
        return Category::query()->firstOrCreate(['slug' => 'catalog-cat'], ['name' => 'فئة']);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->makeCategory()->id,
            'title' => 'منتج',
            'asin' => 'ASIN'.random_int(1000, 9999),
            'price' => 1000.00,
            'rating' => 4.0,
            'review_count' => 10,
            'affiliate_url' => 'https://www.amazon.eg/dp/'.substr((string) random_int(1000, 9999), 0, 10),
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ], $overrides));
    }

    public function test_pending_sync_requires_valid_token(): void
    {
        $this->getJson('/api/v1/catalog/pending-sync')->assertUnauthorized();
        $this->getJson('/api/v1/catalog/pending-sync', ['x-sync-token' => 'wrong'])->assertUnauthorized();
    }

    public function test_pending_sync_returns_products_null_first_and_respects_limit(): void
    {
        $this->makeProduct(['asin' => 'A-NULL', 'sync_status' => Product::SYNC_STATUS_PENDING, 'last_synced_at' => null]);
        $this->makeProduct(['asin' => 'B-OLD', 'last_synced_at' => now()->subHours(5)]);
        $this->makeProduct(['asin' => 'C-SYNCED', 'sync_status' => Product::SYNC_STATUS_SYNCED, 'last_synced_at' => now()]);

        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=10', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $asins = collect($response->json())->pluck('asin_or_sku')->all();

        $this->assertEquals(['A-NULL', 'B-OLD'], $asins);
        $this->assertEquals('amazon', $response->json()[0]['platform']);
        $this->assertArrayHasKey('url', $response->json()[0]);
    }

    public function test_pending_sync_caps_limit_at_twenty(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeProduct(['asin' => 'CAP-'.$i]);
        }

        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=99', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $this->assertCount(20, $response->json());
    }

    public function test_sync_results_success_updates_price_and_marks_synced(): void
    {
        $product = $this->makeProduct(['price' => 1000.00]);
        $createdAt = $product->created_at;

        $response = $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 18521.00,
                'was_price' => 20000.00,
                'in_stock' => true,
                'title' => 'عنوان محدث',
                'image_url' => 'https://example.com/img.jpg',
                'sync_status' => 'success',
                'error_reason' => null,
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $this->assertEquals(1, $response->json('price_updates'));

        $product->refresh();
        $this->assertEquals(18521.00, (float) $product->price);
        $this->assertEquals(20000.00, (float) $product->original_price);
        $this->assertTrue($product->in_stock);
        $this->assertEquals(Product::SYNC_STATUS_SYNCED, $product->sync_status);
        $this->assertEquals(0, (int) $product->sync_attempts);
        $this->assertNull($product->last_sync_error);
        $this->assertNotNull($product->last_synced_at);
    }

    public function test_material_price_change_refreshes_article_and_dispatches_jobs(): void
    {
        Queue::fake();

        $product = $this->makeProduct(['price' => 1000.00]);
        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'title' => 'مقال',
            'slug' => 'catalog-article-'.uniqid(),
            'content' => '<p>محتوى</p>',
            'is_published' => true,
            'updated_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1100.00,
                'in_stock' => true,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $this->assertGreaterThanOrEqual($article->updated_at->subSecond(), $article->fresh()->updated_at);

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function (PurgeCloudflareCacheJob $job) use ($article) {
            return collect($job->urls)->contains(fn (string $url) => str_contains($url, $article->slug));
        });
    }

    public function test_sync_results_syncs_rating_review_count_and_ignores_was_price_not_higher(): void
    {
        $product = $this->makeProduct(['price' => 1000.00, 'rating' => 4.0, 'review_count' => 10, 'original_price' => 1200.00]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1000.00,
                'was_price' => 900.00,
                'in_stock' => true,
                'rating' => 4.6,
                'review_count' => 320,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();

        $this->assertEquals(4.6, (float) $product->rating);
        $this->assertEquals(320, (int) $product->review_count);
        $this->assertEquals(1200.00, (float) $product->original_price);
    }

    public function test_sync_results_failure_increments_attempts_and_flags_after_limit(): void
    {
        $product = $this->makeProduct(['sync_attempts' => 3]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1000.00,
                'in_stock' => true,
                'sync_status' => 'failed',
                'error_reason' => 'blocked by captcha',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();
        $this->assertEquals(4, (int) $product->sync_attempts);
        $this->assertEquals('blocked by captcha', $product->last_sync_error);
        $this->assertEquals(Product::SYNC_STATUS_PENDING, $product->sync_status);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1000.00,
                'in_stock' => true,
                'sync_status' => 'failed',
                'error_reason' => 'retries exhausted',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();
        $this->assertEquals(5, (int) $product->sync_attempts);
        $this->assertEquals(Product::SYNC_STATUS_FAILED, $product->sync_status);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Jobs\UploadProductImageToR2Job;
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

    public function test_synced_products_are_only_requeued_by_the_reset_cron(): void
    {
        $this->makeProduct(['asin' => 'STALE-SYNCED', 'sync_status' => Product::SYNC_STATUS_SYNCED, 'last_synced_at' => now()->subHours(7)]);

        // A synced product must NOT come back through pending-sync directly — the
        // 6-hour cron (catalog:reset-sync-queue) owns requeueing.
        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=10', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $this->assertSame([], $response->json());

        // Run the reset cron -> product flips to pending and enters the queue.
        $this->artisan('catalog:reset-sync-queue')->assertSuccessful();

        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=10', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $this->assertEquals('STALE-SYNCED', $response->json()[0]['asin_or_sku']);
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

    public function test_sync_results_out_of_stock_is_success_not_failure(): void
    {
        $product = $this->makeProduct(['price' => 15000.00, 'in_stock' => true]);

        $response = $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'in_stock' => false,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $this->assertEquals(0, $response->json('price_updates'));

        $product->refresh();
        $this->assertFalse($product->in_stock);
        $this->assertEquals(Product::SYNC_STATUS_SYNCED, $product->sync_status);
        $this->assertEquals(15000.00, (float) $product->price);
        $this->assertNotNull($product->last_synced_at);

        // A previous in-stock price must not be dropped to 0 or bucketed into history.
        $this->assertDoesntHaveSyncedPriceHistory($product);
    }

    public function test_sync_results_out_of_stock_moves_off_pending_queue_and_can_revive(): void
    {
        $out = $this->makeProduct(['asin' => 'OOS-REVIVE', 'in_stock' => true, 'price' => 9000.00]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $out->id,
                'live_price' => 0,
                'in_stock' => false,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        // No longer in the pending queue.
        $pendingAsins = Product::query()->pendingForCatalogSync()->pluck('asin')->all();
        $this->assertNotContains('OOS-REVIVE', $pendingAsins);

        // Next sync finds it in stock again -> price + state restored.
        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $out->id,
                'live_price' => 9200.00,
                'in_stock' => true,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $out->refresh();
        $this->assertTrue($out->in_stock);
        $this->assertEquals(9200.00, (float) $out->price);
    }

    private function assertDoesntHaveSyncedPriceHistory(Product $product): void
    {
        $this->assertEmpty($product->priceHistories()->get()->all());
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

    public function test_sync_results_keeps_existing_r2_image_and_skips_reupload(): void
    {
        Queue::fake();

        $r2Url = 'https://pub-749a9324c4ca4614a48ba20c65c376c1.r2.dev/products/b01lcvq0uy.webp';
        $product = $this->makeProduct([
            'price' => 1000.00,
            'image_url' => $r2Url,
        ]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1000.00,
                'in_stock' => true,
                'image_url' => 'https://m.media-amazon.com/images/I/31g1yQGZcsL.jpg',
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();

        // The stored R2 URL must be retained, not overwritten by the Amazon URL.
        $this->assertSame($r2Url, $product->image_url);

        // No re-download / watermark / upload must be queued for an R2 product.
        Queue::assertNotPushed(UploadProductImageToR2Job::class);
    }

    public function test_sync_results_migrates_external_image_to_r2_once(): void
    {
        Queue::fake();

        $amazonUrl = 'https://m.media-amazon.com/images/I/31g1yQGZcsL.jpg';
        $product = $this->makeProduct([
            'price' => 1000.00,
            'image_url' => $amazonUrl,
        ]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 1000.00,
                'in_stock' => true,
                'image_url' => $amazonUrl,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();

        // External image is kept so the upload job mirrors it to R2 exactly once.
        $this->assertSame($amazonUrl, $product->image_url);
        Queue::assertPushed(UploadProductImageToR2Job::class, function (UploadProductImageToR2Job $job) use ($product, $amazonUrl) {
            return $job->productId === $product->id && $job->imageUrl === $amazonUrl;
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
        $this->assertNotNull($product->last_synced_at);

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

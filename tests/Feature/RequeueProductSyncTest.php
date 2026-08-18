<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequeueProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'x-catalog-sync-token-2026';

    public function test_requeue_flips_product_to_pending_and_puts_it_first_in_queue(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'requeue-cat'], ['name' => 'فئة']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج معاد',
            'asin' => 'REQUEAE01',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/REQUEAE01',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'sync_attempts' => 3,
            'last_sync_error' => 'previous failure',
            'last_synced_at' => now(),
        ]);

        $this->artisan('products:requeue', ['ids' => [(string) $product->id]])
            ->expectsOutputToContain("Product #{$product->id} requeued as pending")
            ->assertExitCode(0);

        $product->refresh();
        $this->assertSame(Product::SYNC_STATUS_PENDING, $product->sync_status);
        $this->assertSame(0, $product->sync_attempts);
        $this->assertNull($product->last_sync_error);
        $this->assertNull($product->last_synced_at);

        // The product is now the FIRST item the scraper sees in pending-sync.
        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=10', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $this->assertSame((int) $product->id, (int) $response->json()[0]['id']);
    }

    public function test_requeue_reports_missing_and_inactive_products(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'requeue-cat2'], ['name' => 'فئة']);

        $inactive = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج غير نشط',
            'asin' => 'REQUEIN01',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/REQUEIN01',
            'in_stock' => true,
            'is_active' => false,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'last_synced_at' => now(),
        ]);

        $this->artisan('products:requeue', ['ids' => ['999999', (string) $inactive->id]])
            ->expectsOutputToContain('not found')
            ->expectsOutputToContain('inactive')
            ->assertExitCode(0);

        $inactive->refresh();
        $this->assertSame(Product::SYNC_STATUS_PENDING, $inactive->sync_status);

        // Inactive products are not picked up by the pending queue.
        $this->getJson('/api/v1/catalog/pending-sync', ['x-sync-token' => self::TOKEN])
            ->assertOk()
            ->assertExactJson([]);
    }
}

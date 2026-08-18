<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Jobs\SyncProductFromAmazonJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ProductBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin-bulk@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }

    private function makeProduct(string $asin, bool $active = true): Product
    {
        $category = Category::create([
            'name' => 'فئة '.$asin,
            'slug' => 'cat-'.strtolower($asin),
            'description' => 'وصف',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'title' => 'منتج '.$asin,
            'asin' => $asin,
            'brand' => 'ماركة',
            'price' => 1000,
            'rating' => 4.5,
            'review_count' => 30,
            'in_stock' => true,
            'is_active' => $active,
            'supports_installment' => true,
            'platform' => 'amazon',
            'affiliate_url' => "https://www.amazon.eg/dp/{$asin}?tag=demo-21",
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'sync_attempts' => 0,
            'last_synced_at' => now(),
        ]);
    }

    public function test_sync_now_requeues_selected_products_and_dispatches_the_sync_job(): void
    {
        Queue::fake();

        $active = $this->makeProduct('BULKSYNCT1');
        $inactive = $this->makeProduct('BULKSYNCT2', active: false);

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableBulkAction('syncNow', [$active, $inactive])
            ->assertHasNoTableBulkActionErrors();

        $active->refresh();
        $this->assertSame(Product::SYNC_STATUS_PENDING, $active->sync_status);
        $this->assertNull($active->last_synced_at);
        $this->assertSame(0, $active->sync_attempts);

        // Inactive products are skipped — they are not part of the pending queue.
        $inactive->refresh();
        $this->assertSame(Product::SYNC_STATUS_SYNCED, $inactive->sync_status);

        Queue::assertPushed(SyncProductFromAmazonJob::class, fn ($job) => $job->productId === $active->id);
        Queue::assertPushed(SyncProductFromAmazonJob::class, 1);
    }

    public function test_activate_bulk_action_enables_selected_products(): void
    {
        $a = $this->makeProduct('BULKACT01', active: false);
        $b = $this->makeProduct('BULKACT02', active: false);

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableBulkAction('activate', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2, Product::query()->where('is_active', true)->count());
        $this->assertTrue($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
    }

    public function test_deactivate_bulk_action_disables_selected_products(): void
    {
        $a = $this->makeProduct('BULKDEAC1');
        $b = $this->makeProduct('BULKDEAC2');

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableBulkAction('deactivate', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(0, Product::query()->where('is_active', true)->count());
        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
    }

    public function test_requeue_bulk_action_moves_products_to_front_of_pending_queue(): void
    {
        $product = $this->makeProduct('BULKREQ01');

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableBulkAction('requeue', [$product])
            ->assertHasNoTableBulkActionErrors();

        $product->refresh();
        $this->assertSame(Product::SYNC_STATUS_PENDING, $product->sync_status);
        $this->assertNull($product->last_synced_at);

        // The product must appear FIRST in the pending catalog sync queue.
        $response = $this->getJson('/api/v1/catalog/pending-sync?limit=10', ['x-sync-token' => 'x-catalog-sync-token-2026'])
            ->assertOk();

        $this->assertSame((int) $product->id, (int) $response->json()[0]['id']);
    }
}

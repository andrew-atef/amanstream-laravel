<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeepScrapeFilamentTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin-deep@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'is_admin' => true,
            ],
        );
    }

    private function makeProduct(array $overrides = []): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'deep-filament-cat'],
            ['name' => 'فئة', 'description' => 'وصف'],
        );

        return Product::create(array_merge([
            'category_id' => $category->id,
            'title' => 'منتج عميق',
            'asin' => 'DEEPADMIN1',
            'price' => 1000,
            'rating' => 4.5,
            'review_count' => 30,
            'in_stock' => true,
            'is_active' => true,
            'supports_installment' => true,
            'platform' => 'amazon',
            'affiliate_url' => 'https://www.amazon.eg/dp/DEEPADMIN1?tag=demo-21',
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_IDLE,
        ], $overrides));
    }

    public function test_bulk_action_requests_deep_scrape_for_selected_products(): void
    {
        $a = $this->makeProduct(['asin' => 'DEEPADMIN2']);
        $b = $this->makeProduct(['asin' => 'DEEPADMIN3']);

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableBulkAction('requestDeepScrape', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_PENDING, $a->fresh()->deep_scrape_status);
        $this->assertSame(Product::DEEP_SCRAPE_STATUS_PENDING, $b->fresh()->deep_scrape_status);
    }

    public function test_row_action_requests_deep_scrape_for_single_product(): void
    {
        $product = $this->makeProduct();

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableAction('requestDeepScrape', $product)
            ->assertHasNoTableActionErrors();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_PENDING, $product->fresh()->deep_scrape_status);
    }

    public function test_approve_row_action_clears_the_diff_alert(): void
    {
        $product = $this->makeProduct([
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED,
            'spec_diff_json' => [
                ['section' => 'خدمات التركيب', 'change' => 'تغيّر سعر التركيب من 500 إلى 547.2 ج.م'],
            ],
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->callTableAction('approveDeepScrape', $product)
            ->assertHasNoTableActionErrors();

        $product->refresh();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_SYNCED, $product->deep_scrape_status);
        $this->assertNull($product->spec_diff_json);
    }

    public function test_edit_form_renders_diff_alert_and_hides_it_when_no_diff(): void
    {
        $changed = $this->makeProduct([
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED,
            'spec_diff_json' => [
                ['section' => 'المواصفات السريعة', 'change' => 'تغيّر سعة التبريد من 1.5 حصان إلى 1.6 حصان'],
                ['section' => 'خدمات التركيب', 'change' => 'تغيّر سعر التركيب من 500 إلى 547.2 ج.م'],
            ],
        ]);

        $html = Livewire::actingAs($this->adminUser())
            ->test(EditProduct::class, ['record' => $changed->id])
            ->html();

        $this->assertStringContainsString('سجل التغييرات المكتشفة في مواصفات أمازون ⚠️', $html);
        $this->assertStringContainsString('تغيّر سعة التبريد من 1.5 حصان إلى 1.6 حصان', $html);
        $this->assertStringContainsString('تغيّر سعر التركيب من 500 إلى 547.2 ج.م', $html);

        $clean = $this->makeProduct(['asin' => 'DEEPADMIN4']);

        $cleanHtml = Livewire::actingAs($this->adminUser())
            ->test(EditProduct::class, ['record' => $clean->id])
            ->html();

        $this->assertStringNotContainsString('سجل التغييرات المكتشفة في مواصفات أمازون ⚠️', $cleanHtml);
    }

    public function test_list_page_renders_deep_scrape_badge(): void
    {
        $this->makeProduct([
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED,
        ]);

        $html = Livewire::actingAs($this->adminUser())
            ->test(ListProducts::class)
            ->html();

        $this->assertStringContainsString('تغيرت المواصفات', $html);
        $this->assertStringContainsString('السحب العميق', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductListTabsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }

    private function seedProducts(): Category
    {
        $category = Category::create(['name' => 'تكييفات', 'slug' => 'air-conditioners']);

        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج متزامن',
            'asin' => 'TAB0000001',
            'affiliate_url' => 'https://www.amazon.eg/dp/TAB0000001',
            'price' => 100,
            'in_stock' => true,
            'is_active' => true,
            'sync_status' => Product::SYNC_STATUS_SYNCED,
        ]);
        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج نافد',
            'asin' => 'TAB0000002',
            'affiliate_url' => 'https://www.amazon.eg/dp/TAB0000002',
            'price' => 200,
            'in_stock' => false,
            'is_active' => true,
            'sync_status' => Product::SYNC_STATUS_SYNCED,
        ]);
        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج معطل',
            'asin' => 'TAB0000003',
            'affiliate_url' => 'https://www.amazon.eg/dp/TAB0000003',
            'price' => 300,
            'in_stock' => true,
            'is_active' => false,
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ]);
        Product::create([
            'category_id' => $category->id,
            'title' => 'منتج فاشل',
            'asin' => 'TAB0000004',
            'affiliate_url' => 'https://www.amazon.eg/dp/TAB0000004',
            'price' => 400,
            'in_stock' => true,
            'is_active' => true,
            'sync_status' => Product::SYNC_STATUS_FAILED,
        ]);

        return $category;
    }

#[Test]
    public function product_list_renders_tabs_with_all_status_badges(): void
    {
        $this->seedProducts();

        $this->actingAs($this->admin())
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('جميع المنتجات', false)
            ->assertSee('نشطة', false)
            ->assertSee('غير نشطة', false)
            ->assertSee('غير متوفرة', false)
            ->assertSee('بانتظار المزامنة', false)
            ->assertSee('مزامَنة', false)
            ->assertSee('فشلت المزامنة', false)
            // The active-state column is rendered per product row.
            ->assertSee('نشط', false);
    }

    #[Test]
    public function each_tab_isolates_its_own_product_set(): void
    {
        $this->seedProducts();

        $this->actingAs($this->admin());

        $sets = [
            'active' => ['منتج متزامن', 'منتج نافد', 'منتج فاشل'],
            'inactive' => ['منتج معطل'],
            'out_of_stock' => ['منتج نافد'],
            'pending' => ['منتج معطل'],
            'synced' => ['منتج متزامن', 'منتج نافد'],
            'failed' => ['منتج فاشل'],
        ];

        foreach ($sets as $tab => $expectedTitles) {
            $html = $this->get('/admin/products?activeTab='.$tab)->getContent();

            foreach ($expectedTitles as $title) {
                $this->assertStringContainsString($title, $html, "tab [$tab] should show $title");
            }

            $otherTitles = array_diff(
                ['منتج متزامن', 'منتج نافد', 'منتج معطل', 'منتج فاشل'],
                $expectedTitles
            );
            foreach ($otherTitles as $title) {
                $this->assertStringNotContainsString($title, $html, "tab [$tab] must hide $title");
            }
        }
    }
}

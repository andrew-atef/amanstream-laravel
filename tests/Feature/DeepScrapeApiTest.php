<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeepScrapeApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'x-catalog-sync-token-2026';

    private function makeCategory(): Category
    {
        return Category::query()->firstOrCreate(['slug' => 'deep-scrape-cat'], ['name' => 'فئة']);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->makeCategory()->id,
            'title' => 'منتج اختبار',
            'asin' => 'B0H2BF6HKJ',
            'price' => 5100.00,
            'original_price' => 6000.00,
            'rating' => 4.5,
            'review_count' => 120,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0H2BF6HKJ?tag=demo',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_SYNCED,
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_IDLE,
        ], $overrides));
    }

    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'id' => $product->id,
            'asin' => $product->asin,
            'title' => 'اسكتش مصدر الموديل الأصلي',
            'brand' => 'ماركة تجريبية',
            'warranty_programs' => [
                ['name' => 'ضمان إضافي سنتين', 'price' => 500, 'duration' => 'سنتان'],
            ],
            'installation_services' => [
                ['name' => 'التركيب', 'price' => 500],
            ],
            'quick_specs' => [
                ['label' => 'سعة التبريد', 'value' => '1.5 حصان'],
            ],
            'about_this_item' => ['مروحة عالية الكفاءة'],
            'technical_details' => [
                ['label' => 'رقم الموديل', 'value' => 'AC-15HP-2026'],
            ],
            'manufacturer_content' => 'نص تسويقي من الشركة المصنعة...',
            'product_description' => 'وصف طويل للمنتج...',
            'customer_reviews_sample' => [
                ['author' => 'أحمد', 'rating' => '5', 'text' => 'منتج ممتاز'],
                ['author' => 'سارة', 'rating' => '4', 'text' => 'جيد جداً'],
            ],
            'raw_amazon_data_text' => "نصوص خام من أمازون\nسطر ثاني",
        ], $overrides);
    }

    public function test_pending_requires_valid_token(): void
    {
        $this->getJson('/api/v1/deep-scrape/pending')->assertUnauthorized();
        $this->getJson('/api/v1/deep-scrape/pending', ['x-sync-token' => 'wrong'])->assertUnauthorized();
    }

    public function test_pending_returns_only_active_pending_products(): void
    {
        $pending = $this->makeProduct([
            'asin' => 'PENDING01',
            'affiliate_url' => 'https://www.amazon.eg/dp/PENDING01?tag=demo',
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_PENDING,
        ]);
        $this->makeProduct([
            'asin' => 'INACTIVE01',
            'is_active' => false,
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_PENDING,
        ]);
        $this->makeProduct([
            'asin' => 'SYNCED01',
            'deep_scrape_status' => Product::DEEP_SCRAPE_STATUS_SYNCED,
        ]);

        $response = $this->getJson('/api/v1/deep-scrape/pending', ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $response->assertJsonCount(1);
        $this->assertSame($pending->id, $response->json('0.id'));
        $this->assertSame('PENDING01', $response->json('0.asin'));
        $this->assertSame('https://www.amazon.eg/dp/PENDING01?tag=demo', $response->json('0.url'));
        $this->assertStringContainsString('no-cache, no-store', $response->headers->get('Cache-Control', ''));
    }

    public function test_first_submit_is_baseline_and_stores_editorial_specs(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/deep-scrape/submit', $this->payload($product), ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $response->assertJson([
            'success' => true,
            'status' => Product::DEEP_SCRAPE_STATUS_SYNCED,
            'diff_count' => 0,
        ]);

        $product->refresh();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_SYNCED, $product->deep_scrape_status);
        $this->assertNull($product->spec_diff_json);
        $this->assertSame('منتج اختبار', $product->title);
        $this->assertNull($product->brand);
        $this->assertSame('نصوص خام من أمازون'."\n".'سطر ثاني', $product->raw_amazon_data);
        $this->assertSame('1.5 حصان', $product->deep_specs_json['quick_specs'][0]['value']);
        $this->assertSame('سنتان', $product->deep_specs_json['warranty_programs'][0]['duration']);
        $this->assertSame('منتج ممتاز', $product->deep_specs_json['customer_reviews_sample'][0]['text']);
        $this->assertArrayNotHasKey('title', $product->deep_specs_json);
        $this->assertArrayNotHasKey('brand', $product->deep_specs_json);
        $this->assertArrayNotHasKey('price', $product->deep_specs_json);
        $this->assertArrayHasKey('customer_reviews_sample', $product->deep_specs_json);
        $this->assertNotNull($product->deep_scraped_at);
    }

    public function test_submit_never_touches_commercial_pricing_fields(): void
    {
        $product = $this->makeProduct();

        $payload = $this->payload($product);
        // The worker may still leak commercial noise (top-level and nested) —
        // the controller must drop and ignore all of it.
        $payload['price'] = 2500;
        $payload['live_price'] = 2500;
        $payload['original_price'] = 999;
        $payload['in_stock'] = false;
        $payload['rating'] = 1.0;
        $payload['review_count'] = 1;
        $payload['pricing'] = ['live_price' => 2500, 'list_price' => 999];
        $payload['availability'] = ['in_stock' => false];

        $this->postJson('/api/v1/deep-scrape/submit', $payload, ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $product->refresh();

        $this->assertSame(5100.0, (float) $product->price);
        $this->assertSame(6000.0, (float) $product->original_price);
        $this->assertTrue($product->in_stock);
        $this->assertSame(4.5, (float) $product->rating);
        $this->assertSame(120, $product->review_count);
        $this->assertNull($product->price_history_json);
        $this->assertNull($product->lowest_price);
        $this->assertArrayNotHasKey('pricing', $product->deep_specs_json);
        $this->assertArrayNotHasKey('availability', $product->deep_specs_json);
        $this->assertArrayNotHasKey('price', $product->deep_specs_json);
        $this->assertArrayNotHasKey('live_price', $product->deep_specs_json);
        $this->assertArrayNotHasKey('original_price', $product->deep_specs_json);
        $this->assertArrayNotHasKey('in_stock', $product->deep_specs_json);
        $this->assertArrayNotHasKey('rating', $product->deep_specs_json);
        $this->assertArrayNotHasKey('review_count', $product->deep_specs_json);
    }

    public function test_second_submit_with_changes_flags_specs_changed(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/deep-scrape/submit', $this->payload($product), ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $changed = $this->payload($product);
        $changed['warranty_programs'][0]['price'] = 547.2;
        $changed['quick_specs'][0]['value'] = '1.6 حصان';

        $response = $this->postJson('/api/v1/deep-scrape/submit', $changed, ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('status', Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED)
            ->assertJsonPath('diff_count', 2);

        $product->refresh();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_SPECS_CHANGED, $product->deep_scrape_status);
        $this->assertCount(2, $product->spec_diff_json);

        $changes = implode(' | ', array_column($product->spec_diff_json, 'change'));
        $this->assertStringContainsString('تغيّر ضمان إضافي سنتين — السعر من 500 ج.م إلى 547.2 ج.م', $changes);
        $this->assertStringContainsString('تغيّر سعة التبريد من 1.5 حصان إلى 1.6 حصان', $changes);

        $sections = array_column($product->spec_diff_json, 'section');
        $this->assertContains('الضمان', $sections);
        $this->assertContains('المواصفات السريعة', $sections);
    }

    public function test_second_submit_with_identical_payload_stays_synced(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/deep-scrape/submit', $this->payload($product), ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $response = $this->postJson('/api/v1/deep-scrape/submit', $this->payload($product), ['x-sync-token' => self::TOKEN])
            ->assertOk();

        $response->assertJsonPath('status', Product::DEEP_SCRAPE_STATUS_SYNCED)
            ->assertJsonPath('diff_count', 0);

        $product->refresh();
        $this->assertSame(Product::DEEP_SCRAPE_STATUS_SYNCED, $product->deep_scrape_status);
        $this->assertNull($product->spec_diff_json);
    }

    public function test_submit_rejects_asin_mismatch(): void
    {
        $product = $this->makeProduct();

        $payload = $this->payload($product);
        $payload['asin'] = 'WRONGASIN01';

        $this->postJson('/api/v1/deep-scrape/submit', $payload, ['x-sync-token' => self::TOKEN])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', "ASIN mismatch: product #{$product->id} expects B0H2BF6HKJ, got WRONGASIN01.");

        $product->refresh();

        $this->assertSame(Product::DEEP_SCRAPE_STATUS_IDLE, $product->deep_scrape_status);
    }

    public function test_submit_validates_required_fields(): void
    {
        $this->postJson('/api/v1/deep-scrape/submit', [
            'asin' => 'B0H2BF6HKJ',
        ], ['x-sync-token' => self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id', 'raw_amazon_data_text']);

        $this->postJson('/api/v1/deep-scrape/submit', [
            'id' => 999999,
            'asin' => 'B0H2BF6HKJ',
            'raw_amazon_data_text' => 'نص',
        ], ['x-sync-token' => self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }
}

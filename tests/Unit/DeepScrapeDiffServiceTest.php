<?php

namespace Tests\Unit;

use App\Services\DeepScrapeDiffService;
use PHPUnit\Framework\TestCase;

class DeepScrapeDiffServiceTest extends TestCase
{
    private DeepScrapeDiffService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DeepScrapeDiffService();
    }

    private function baseline(): array
    {
        return [
            'id' => 1,
            'asin' => 'B0H2BF6HKJ',
            'quick_specs' => [
                ['label' => 'سعة التبريد', 'value' => '1.5 حصان'],
                ['label' => 'مستوى الضوضاء', 'value' => '22 ديسيبل'],
            ],
            'warranty_addons' => [
                ['name' => 'ضمان إضافي سنتين', 'price' => 500, 'duration' => 'سنتان'],
            ],
            'additional_services' => [
                ['name' => 'التركيب', 'price' => 500],
            ],
            'about_this_item' => ['مروحة عالية الكفاءة', 'توفير في الكهرباء'],
            'product_description' => 'وصف طويل للمنتج...',
            'pricing' => ['live_price' => 5000, 'list_price' => 6000],
            'availability' => ['in_stock' => true],
        ];
    }

    public function test_identical_payload_produces_no_diffs(): void
    {
        $payload = $this->baseline();

        $this->assertSame([], $this->service->diff($payload, $payload));
    }

    public function test_first_scrape_is_a_baseline_not_a_diff(): void
    {
        $this->assertSame([], $this->service->diff(null, $this->baseline()));
        $this->assertSame([], $this->service->diff([], $this->baseline()));
    }

    public function test_pricing_live_price_change_is_detected(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['pricing']['live_price'] = 547.2;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('السعر', $diffs[0]['category']);
        $this->assertStringContainsString('تغيّر السعر الحالي من 5,000 ج.م إلى 547.2 ج.م', $diffs[0]['change']);
    }

    public function test_availability_in_stock_change_is_detected(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['availability']['in_stock'] = false;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('التوفر', $diffs[0]['category']);
        $this->assertSame('تغيّر التوفر من متوفر إلى غير متوفر', $diffs[0]['change']);
    }

    public function test_quick_spec_value_change_pairs_rows_by_label(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['quick_specs'][0]['value'] = '1.6 حصان';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('المواصفات السريعة', $diffs[0]['category']);
        $this->assertSame('تغيّر سعة التبريد من 1.5 حصان إلى 1.6 حصان', $diffs[0]['change']);
    }

    public function test_warranty_price_change_is_reported_with_arabic_labels(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['warranty_addons'][0]['price'] = 550;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('عروض الضمان الإضافية', $diffs[0]['category']);
        $this->assertSame('تغيّر ضمان إضافي سنتين — السعر من 500 ج.م إلى 550 ج.م', $diffs[0]['change']);
    }

    public function test_installation_service_price_change_is_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['additional_services'][0]['price'] = 547.2;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('الخدمات والتركيب', $diffs[0]['category']);
        $this->assertSame('تغيّر التركيب — السعر من 500 ج.م إلى 547.2 ج.م', $diffs[0]['change']);
    }

    public function test_about_this_item_adds_and_removes_bullets(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['about_this_item'] = ['مروحة عالية الكفاءة', 'ضمان شامل'];

        $diffs = $this->service->diff($old, $new);

        $changes = array_column($diffs, 'change');
        $this->assertSame('نبذة عن هذا المنتج', $diffs[0]['category']);
        $this->assertStringContainsString('تمت إزالة «توفير في الكهرباء»', implode(' | ', $changes));
        $this->assertStringContainsString('تمت إضافة «ضمان شامل»', implode(' | ', $changes));
    }

    public function test_product_description_change_produces_single_entry(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['product_description'] = 'وصف جديد كلياً...';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('وصف المنتج', $diffs[0]['category']);
        $this->assertSame('تغيّر محتوى وصف المنتج', $diffs[0]['change']);
    }

    public function test_new_warranty_row_is_reported_as_added(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['warranty_addons'][] = ['name' => 'ضمان إضافي ثلاث سنوات', 'price' => 750, 'duration' => 'ثلاث سنوات'];

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('تمت إضافة «ضمان إضافي ثلاث سنوات»', $diffs[0]['change']);
    }

    public function test_spec_row_order_change_is_not_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['quick_specs'] = [
            ['label' => 'مستوى الضوضاء', 'value' => '22 ديسيبل'],
            ['label' => 'سعة التبريد', 'value' => '1.5 حصان'],
        ];

        $this->assertSame([], $this->service->diff($old, $new));
    }
}
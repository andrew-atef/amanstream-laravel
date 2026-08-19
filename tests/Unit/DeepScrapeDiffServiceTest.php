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

        $this->service = new DeepScrapeDiffService;
    }

    private function baseline(): array
    {
        return [
            'id' => 1,
            'asin' => 'B0H2BF6HKJ',
            'warranty_programs' => [
                ['name' => 'ضمان إضافي سنتين', 'price' => 500, 'duration' => 'سنتان'],
            ],
            'installation_services' => [
                ['name' => 'التركيب', 'price' => 500],
            ],
            'quick_specs' => [
                ['label' => 'سعة التبريد', 'value' => '1.5 حصان'],
                ['label' => 'مستوى الضوضاء', 'value' => '22 ديسيبل'],
            ],
            'about_this_item' => ['مروحة عالية الكفاءة', 'توفير في الكهرباء'],
            'technical_details' => [
                ['label' => 'رقم الموديل', 'value' => 'AC-15HP-2026'],
            ],
            'manufacturer_content' => 'نص تسويقي من الشركة المصنعة حول تقنية التبريد الفائق...',
            'product_description' => 'وصف طويل للمنتج...',
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

    public function test_pricing_and_availability_keys_are_ignored_by_the_editorial_engine(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['pricing'] = ['live_price' => 999, 'list_price' => 1200];
        $new['availability'] = ['in_stock' => false];
        $new['rating'] = 1.0;
        $new['review_count'] = 1;

        $this->assertSame([], $this->service->diff($old, $new));
    }

    public function test_customer_reviews_sample_is_stored_but_never_diffed(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['customer_reviews_sample'] = [
            ['author' => 'أحمد', 'rating' => '5', 'text' => 'مراجعة جديدة تماماً'],
        ];

        $this->assertSame([], $this->service->diff($old, $new));
    }

    public function test_quick_spec_value_change_pairs_rows_by_label(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['quick_specs'][0]['value'] = '1.6 حصان';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('المواصفات السريعة', $diffs[0]['section']);
        $this->assertSame('تغيّر سعة التبريد من 1.5 حصان إلى 1.6 حصان', $diffs[0]['change']);
        $this->assertSame('1.5 حصان', $diffs[0]['old']);
        $this->assertSame('1.6 حصان', $diffs[0]['new']);
    }

    public function test_warranty_addon_price_change_is_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['warranty_programs'][0]['price'] = 550;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('الضمان', $diffs[0]['section']);
        $this->assertSame('تغيّر ضمان إضافي سنتين — السعر من 500 ج.م إلى 550 ج.م', $diffs[0]['change']);
    }

    public function test_warranty_duration_change_is_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['warranty_programs'][0]['duration'] = 'ثلاث سنوات';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('الضمان', $diffs[0]['section']);
        $this->assertSame('تغيّر ضمان إضافي سنتين — المدة من سنتان إلى ثلاث سنوات', $diffs[0]['change']);
    }

    public function test_installation_service_price_change_is_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['installation_services'][0]['price'] = 547.2;

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('خدمات التركيب', $diffs[0]['section']);
        $this->assertSame('تغيّر التركيب — السعر من 500 ج.م إلى 547.2 ج.م', $diffs[0]['change']);
    }

    public function test_technical_details_change_is_reported(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['technical_details'][0]['value'] = 'AC-15HP-2027';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('التفاصيل الفنية', $diffs[0]['section']);
        $this->assertSame('تغيّر رقم الموديل من AC-15HP-2026 إلى AC-15HP-2027', $diffs[0]['change']);
    }

    public function test_manufacturer_content_change_is_reported_as_single_entry(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['manufacturer_content'] = 'نص تسويقي محدث بالكامل...';

        $diffs = $this->service->diff($old, $new);

        $this->assertCount(1, $diffs);
        $this->assertSame('محتوى الشركة المصنعة', $diffs[0]['section']);
        $this->assertSame('تغيّر محتوى الشركة المصنعة', $diffs[0]['change']);
    }

    public function test_about_this_item_adds_and_removes_bullets(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['about_this_item'] = ['مروحة عالية الكفاءة', 'ضمان شامل'];

        $diffs = $this->service->diff($old, $new);

        $changes = array_column($diffs, 'change');
        $this->assertSame('نبذة عن هذا المنتج', $diffs[0]['section']);
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
        $this->assertSame('وصف المنتج', $diffs[0]['section']);
        $this->assertSame('تغيّر محتوى وصف المنتج', $diffs[0]['change']);
    }

    public function test_new_warranty_program_is_reported_as_added(): void
    {
        $old = $this->baseline();
        $new = $this->baseline();
        $new['warranty_programs'][] = ['name' => 'ضمان إضافي ثلاث سنوات', 'price' => 750, 'duration' => 'ثلاث سنوات'];

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

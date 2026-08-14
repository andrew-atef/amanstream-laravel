<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'x-catalog-sync-token-2026';

    private function makeProduct(array $overrides = []): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'history-cat'],
            ['name' => 'تكييفات']
        );

        return Product::create(array_merge([
            'category_id' => $category->id,
            'title' => 'منتج تاريخ السعر',
            'asin' => 'PH'.random_int(1000, 9999),
            'price' => 1000.00,
            'rating' => 4.0,
            'review_count' => 10,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0PRICETEST?tag=demo-21',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
            'sync_status' => Product::SYNC_STATUS_PENDING,
        ], $overrides));
    }

    #[Test]
    public function it_logs_history_only_when_the_price_actually_changes(): void
    {
        $product = $this->makeProduct(['price' => 1000.00]);

        // First ever point: cache is empty so the baseline price is logged.
        $product->recordPriceHistory(1000);
        // Same price again: golden rule #1 — zero new rows.
        $product->recordPriceHistory(1000);
        // Real price change: logged + cached range/json maintained.
        $product->recordPriceHistory(1500, now(), 1000);
        $product->save();

        $this->assertSame(2, ProductPriceHistory::query()->where('product_id', $product->id)->count());

        $product->refresh();
        $this->assertSame(1000.0, (float) $product->lowest_price);
        $this->assertSame(1500.0, (float) $product->highest_price);
        $this->assertCount(2, $product->price_history_json);
        $this->assertSame(1500.0, (float) $product->price_history_json[1]['p']);
    }

    #[Test]
    public function it_cascades_history_when_product_is_deleted(): void
    {
        $product = $this->makeProduct();

        ProductPriceHistory::create([
            'product_id' => $product->id,
            'price' => 1000.00,
            'recorded_at' => now(),
        ]);

        $product->delete();

        $this->assertDatabaseMissing('product_price_histories', ['product_id' => $product->id]);
    }

    #[Test]
    public function it_falls_back_to_sensible_prices_when_no_cache_exists(): void
    {
        $product = $this->makeProduct(['price' => 10000.00, 'original_price' => 12500.00]);

        $this->assertSame(10000.0, $product->getLowestRecordedPrice());
        $this->assertSame(12500.0, $product->getHighestRecordedPrice());

        $noOriginal = $this->makeProduct(['price' => 10000.00, 'original_price' => null]);

        $this->assertSame(11200.0, $noOriginal->getHighestRecordedPrice());
    }

    #[Test]
    public function it_never_treats_a_zero_snapshot_as_a_real_price(): void
    {
        // lowest_price/highest_price are decimal:2 → returned as the STRING
        // "0.00", which is truthy in PHP. A legacy 0 snapshot must never
        // surface as "أقل سعر سُجِّل 0.00" nor floor the recorded range.
        $product = $this->makeProduct([
            'price' => 23799.00,
            'lowest_price' => 0.00,
            'highest_price' => 0.00,
        ]);

        $this->assertSame(23799.0, $product->getLowestRecordedPrice());
        $this->assertSame(26654.88, $product->getHighestRecordedPrice());

        // A zero snapshot is never written to history or the JSON window.
        $product->recordPriceHistory(0);
        $product->recordPriceHistory(28899);
        $product->save();

        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $product->id)->count());
        $this->assertSame(28899.0, (float) $product->lowest_price);
        $this->assertSame(28899.0, (float) $product->highest_price);
        $this->assertSame(28899.0, (float) $product->price_history_json[0]['p']);
    }

    #[Test]
    public function it_filters_zero_points_out_of_the_chart_data(): void
    {
        $product = $this->makeProduct([
            'price' => 23799.00,
            'price_history_json' => [
                ['p' => 0, 'd' => '08/08'],
                ['p' => 23799, 'd' => '09/08'],
            ],
        ]);

        $points = $product->getPriceHistoryPoints();

        $this->assertCount(1, $points);
        $this->assertSame(23799.0, $points[0]['price']);
        $this->assertSame('09/08', $points[0]['date']);
    }

    #[Test]
    public function it_classifies_the_current_price_against_the_cached_range(): void
    {
        $product = $this->makeProduct([
            'price' => 18000.00,
            'lowest_price' => 18000.00,
            'highest_price' => 24000.00,
        ]);

        $this->assertSame('excellent', $product->getPriceStatus()['status']);
        $this->assertSame('emerald', $product->getPriceStatus()['color']);

        $product->update(['price' => 23500.00]);

        $this->assertSame('high', $product->getPriceStatus()['status']);
        $this->assertSame('rose', $product->getPriceStatus()['color']);

        $product->update(['price' => 20500.00]);

        $this->assertSame('fair', $product->getPriceStatus()['status']);
        $this->assertSame('sky', $product->getPriceStatus()['color']);
    }

    #[Test]
    public function it_rolls_the_cached_json_window_to_the_last_ten_points(): void
    {
        $product = $this->makeProduct(['price' => 100.00]);

        foreach (range(1, 12) as $i) {
            $price = $i * 100;
            $product->recordPriceHistory($price, now()->subDays(12 - $i), $product->price);
            $product->price = $price;
            $product->save();
        }

        $product->refresh();

        $this->assertCount(10, $product->price_history_json);
        $this->assertSame(300.0, (float) $product->price_history_json[0]['p']);
        $this->assertSame(1200.0, (float) $product->price_history_json[9]['p']);
        $this->assertSame(100.0, (float) $product->lowest_price);
        $this->assertSame(1200.0, (float) $product->highest_price);
    }

    #[Test]
    public function sync_results_persist_history_only_when_price_changes(): void
    {
        $product = $this->makeProduct(['price' => 1000.00]);

        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 18521.00,
                'was_price' => 20000.00,
                'in_stock' => true,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'price' => 18521.00,
        ]);

        $product->refresh();
        $this->assertSame(18521.0, (float) $product->lowest_price);
        $this->assertSame(18521.0, (float) $product->highest_price);
        $this->assertCount(1, $product->price_history_json);

        // Identical price on the next sync → zero new history rows.
        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 18521.00,
                'in_stock' => true,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $product->id)->count());

        // A real drop → logged and range widened.
        $this->postJson('/api/v1/catalog/sync-results', [
            'results' => [[
                'id' => $product->id,
                'live_price' => 18000.00,
                'in_stock' => true,
                'sync_status' => 'success',
            ]],
        ], ['x-sync-token' => self::TOKEN])->assertOk();

        $product->refresh();
        $this->assertSame(2, ProductPriceHistory::query()->where('product_id', $product->id)->count());
        $this->assertSame(18000.0, (float) $product->lowest_price);
        $this->assertSame(18521.0, (float) $product->highest_price);
    }

    #[Test]
    public function price_history_widget_renders_from_cached_columns_only(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'history-cat'], ['name' => 'تكييفات']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج تاريخ السعر',
            'asin' => 'B0PRICETEST',
            'price' => 18521.00,
            'lowest_price' => 18520.00,
            'highest_price' => 24000.00,
            'price_history_json' => [
                ['p' => 24000, 'd' => '05/24'],
                ['p' => 21000, 'd' => '06/15'],
                ['p' => 19000, 'd' => '07/20'],
                ['p' => 18520, 'd' => '08/26'],
            ],
            'rating' => 4.0,
            'review_count' => 10,
            'affiliate_url' => 'https://www.amazon.eg/dp/B0PRICETEST?tag=demo-21',
            'in_stock' => true,
        ]);

        $article = Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال تاريخ السعر',
            'slug' => 'price-history-article',
            'content' => '<h2>قبل الشراء</h2><p>[price_history]</p>',
            'is_published' => true,
        ]);

        $html = $this->get('/articles/'.$article->slug)->getContent();

        $this->assertStringNotContainsString('[price_history]', $html);
        $this->assertStringContainsString('مؤشر أمان برايس لتاريخ السعر', $html);
        $this->assertStringContainsString('أقل سعر سُجِّل', $html);
        $this->assertStringContainsString('السعر الحالي اليوم', $html);
        $this->assertStringContainsString('أعلى سعر سُجِّل', $html);
        $this->assertStringContainsString('24,000.00', $html);
        $this->assertStringContainsString('18,521.00', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('data-ph-chart="1"', $html);
        $this->assertStringContainsString('data-ph-labels=', $html);
        $this->assertStringContainsString('data-ph-prices=', $html);
        $this->assertStringContainsString('24,000', $html);
        $this->assertStringContainsString('/icons/amazon.png', $html);
        $this->assertStringContainsString('اشترِ الآن', $html);
        $this->assertStringContainsString('https://www.amazon.eg/dp/B0PRICETEST?tag=demo-21', $html);
    }

    #[Test]
    public function widget_helpers_execute_zero_additional_sql_queries(): void
    {
        $product = Product::query()->firstOrCreate(
            ['asin' => 'B0ZEROSQL'],
            [
                'category_id' => Category::query()->firstOrCreate(['slug' => 'zero-sql'], ['name' => 'اختبار'])->id,
                'title' => 'منتج بدون استعلامات',
                'price' => 18521.00,
                'lowest_price' => 18000.00,
                'highest_price' => 24000.00,
                'price_history_json' => [
                    ['p' => 24000, 'd' => '05/24'],
                    ['p' => 18521, 'd' => '08/26'],
                ],
                'affiliate_url' => 'https://www.amazon.eg/dp/B0ZEROSQL?tag=demo-21',
                'in_stock' => true,
            ]
        );

        // Warm the model, then prove every widget read is pure in-memory.
        $product->refresh();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $product->getPriceStatus();
        $product->getPriceHistoryPoints();
        $product->getLowestRecordedPrice();
        $product->getHighestRecordedPrice();

        $this->assertCount(0, DB::getQueryLog());
    }
}

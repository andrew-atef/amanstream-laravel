<?php

namespace Tests\Feature;

use App\Services\Amazon\AmazonUrlDataFetcher;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AmazonUrlDataFetcherTest extends TestCase
{
    private const URL = 'https://www.amazon.eg/dp/B0TEST1234?tag=tg&linkCode=ur1&psc=1';

    #[Test]
    public function it_extracts_the_asin_from_an_affiliate_url(): void
    {
        $fetcher = app(AmazonUrlDataFetcher::class);

        $this->assertSame('B0TEST1234', $fetcher->extractAsin(self::URL));
        $this->assertNull($fetcher->extractAsin('https://example.com/not-an-amazon-link'));
        $this->assertNull($fetcher->extractAsin('https://www.amazon.eg/gp/product/'));
    }

    #[Test]
    public function it_maps_the_worker_payload_onto_the_form_shape(): void
    {
        Http::fake([
            '*' => Http::response([
                'title' => 'مكيف سبليت 1.5 حصان',
                'live_price' => '18521.00',
                'was_price' => '22000.00',
                'image_url' => 'https://img.example.com/coil.jpg',
                'rating' => 4.5,
                'review_count' => 312,
                'in_stock' => true,
            ]),
        ]);

        $data = app(AmazonUrlDataFetcher::class)->fetch(self::URL);

        $this->assertSame('B0TEST1234', $data['asin']);
        $this->assertSame('مكيف سبليت 1.5 حصان', $data['title']);
        $this->assertSame('18521.00', $data['price']);
        $this->assertSame('22000.00', $data['original_price']);
        $this->assertSame('https://img.example.com/coil.jpg', $data['image_url']);
        $this->assertSame(4.5, $data['rating']);
        $this->assertSame(312, $data['review_count']);
        $this->assertTrue($data['in_stock']);
    }

    #[Test]
    public function it_skips_the_strikethrough_price_when_not_lower_than_live_price(): void
    {
        Http::fake([
            '*' => Http::response([
                'title' => 'منتج',
                'live_price' => '100.00',
                'was_price' => '90.00',
            ]),
        ]);

        $data = app(AmazonUrlDataFetcher::class)->fetch(self::URL);

        $this->assertNull($data['original_price']);
    }

    #[Test]
    public function it_preserves_zero_rating_and_review_count_for_reviewless_products(): void
    {
        Http::fake([
            '*' => Http::response([
                'title' => 'منتج بلا مراجعات',
                'live_price' => '100.00',
                'rating' => 0,
                'review_count' => 0,
            ]),
        ]);

        $data = app(AmazonUrlDataFetcher::class)->fetch(self::URL);

        $this->assertSame(0.0, $data['rating']);
        $this->assertSame(0, $data['review_count']);
    }

    #[Test]
    public function it_keeps_rating_and_review_count_null_when_worker_omits_them(): void
    {
        Http::fake([
            '*' => Http::response([
                'title' => 'منتج',
                'live_price' => '100.00',
            ]),
        ]);

        $data = app(AmazonUrlDataFetcher::class)->fetch(self::URL);

        $this->assertNull($data['rating']);
        $this->assertNull($data['review_count']);
    }

    #[Test]
    public function it_throws_when_the_worker_returns_an_error(): void
    {
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        app(AmazonUrlDataFetcher::class)->fetch(self::URL);
    }
}

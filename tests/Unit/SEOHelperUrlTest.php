<?php

namespace Tests\Unit;

use App\Services\SEOHelper;
use Tests\TestCase;

class SEOHelperUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://www.amanprice.tech']);
    }

    public function test_bare_url_has_no_trailing_slash(): void
    {
        $this->assertSame('https://www.amanprice.tech', SEOHelper::url());
    }

    public function test_simple_path_joins_with_single_slash(): void
    {
        $this->assertSame('https://www.amanprice.tech/favicon.svg', SEOHelper::url('favicon.svg'));
    }

    public function test_leading_slashes_are_collapsed_not_duplicated(): void
    {
        $this->assertSame('https://www.amanprice.tech/favicon.svg', SEOHelper::url('/favicon.svg'));
        $this->assertSame('https://www.amanprice.tech/favicon.svg', SEOHelper::url('//favicon.svg'));
    }

    public function test_interior_double_slash_is_collapsed(): void
    {
        $this->assertSame('https://www.amanprice.tech/articles/foo', SEOHelper::url('articles//foo'));
    }

    public function test_query_string_never_gets_a_bare_double_slash(): void
    {
        $this->assertSame('https://www.amanprice.tech/?q=rice', SEOHelper::url('?q=rice'));
    }

    public function test_multi_segment_path(): void
    {
        $this->assertSame(
            'https://www.amanprice.tech/category/air-conditioners',
            SEOHelper::url('category/air-conditioners')
        );
    }

    public function test_non_www_host_is_normalized_for_https(): void
    {
        config(['app.url' => 'https://amanprice.tech']);

        $this->assertSame('https://www.amanprice.tech/articles/x', SEOHelper::url('articles/x'));
    }

    public function test_local_host_is_left_untouched(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $this->assertSame('http://localhost:8000', SEOHelper::canonical('/'));
        $this->assertSame('http://localhost:8000/articles/x', SEOHelper::canonical('articles/x'));
    }

    public function test_clean_affiliate_url_builds_canonical_link_from_asin(): void
    {
        config(['services.amazon.tag' => 'khatfadeals2-21']);

        $this->assertSame(
            'https://www.amazon.eg/dp/B01LCVQ0UY?tag=khatfadeals2-21',
            SEOHelper::cleanAffiliateUrl('https://www.amazon.eg/dp/b01lcvq0uy?tag=demo', 'b01lcvq0uy')
        );

        // The configured tag always wins over whatever was scraped.
        config(['services.amazon.tag' => 'my-tag-21']);

        $this->assertSame(
            'https://www.amazon.eg/dp/B01LCVQ0UY?tag=my-tag-21',
            SEOHelper::cleanAffiliateUrl(null, 'B01LCVQ0UY')
        );
    }

    public function test_clean_affiliate_url_extracts_asin_and_strips_tracking_junk(): void
    {
        config(['services.amazon.tag' => 'khatfadeals2-21']);

        $messy = 'https://www.amazon.eg/dp/B0TEST1234?ref_=ppx_yo_mob_b_populate_td_t2&dib=eyJ2IjoiMSJ9&dib_tag=se&sprefix=%2Caps%2C247&crid=3GK0X2VX&qid=1716110000&psc=1&tag=demo';

        $this->assertSame(
            'https://www.amazon.eg/dp/B0TEST1234?tag=khatfadeals2-21',
            SEOHelper::cleanAffiliateUrl($messy, null)
        );

        // /gp/product/ style URLs are normalized the same way.
        $this->assertSame(
            'https://www.amazon.eg/dp/B0TEST1234?tag=khatfadeals2-21',
            SEOHelper::cleanAffiliateUrl('https://www.amazon.eg/gp/product/B0TEST1234/ref=ox_sc_act_title_1?th=1&psc=1', null)
        );
    }

    public function test_clean_affiliate_url_keeps_noon_links_clean(): void
    {
        $this->assertSame(
            'https://www.noon.com/egypt-en/smart-tv-12345/p',
            SEOHelper::cleanAffiliateUrl('https://www.noon.com/egypt-en/smart-tv-12345/p?utm_source=aid&refid=xyz', null)
        );
    }

    public function test_clean_affiliate_url_returns_blank_for_empty_input(): void
    {
        $this->assertSame('', SEOHelper::cleanAffiliateUrl(null, null));
        $this->assertSame('', SEOHelper::cleanAffiliateUrl('', ''));
        $this->assertSame('', SEOHelper::cleanAffiliateUrl('   ', ''));
    }

    public function test_clean_affiliate_url_returns_unknown_links_unchanged(): void
    {
        $this->assertSame(
            'https://retailer.example.com/item/42',
            SEOHelper::cleanAffiliateUrl('https://retailer.example.com/item/42', null)
        );
    }
}

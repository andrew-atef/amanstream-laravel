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
}

<?php

namespace Tests\Unit;

use App\Services\SEOHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderDynamicYearTest extends TestCase
{
    #[Test]
    public function it_renders_all_supported_year_token_spellings(): void
    {
        $year = date('Y');

        $this->assertSame($year, SEOHelper::renderDynamicYear('[year]'));
        $this->assertSame($year, SEOHelper::renderDynamicYear('%%year%%'));
        $this->assertSame($year, SEOHelper::renderDynamicYear('{year}'));
        $this->assertSame('أفضل تكييفات '.$year.' في مصر', SEOHelper::renderDynamicYear('أفضل تكييفات [year] في مصر'));
        $this->assertSame('دليل '.$year.' و'.$year.' و'.$year, SEOHelper::renderDynamicYear('دليل [year] و%%year%% و{year}'));
    }

    #[Test]
    public function it_never_touches_text_without_year_tokens(): void
    {
        $this->assertSame(
            'أفضل تكييفات سبليت انفرتر لعام 2025 المقبل',
            SEOHelper::renderDynamicYear('أفضل تكييفات سبليت انفرتر لعام 2025 المقبل')
        );
    }

    #[Test]
    public function it_returns_blank_for_null_and_empty_input(): void
    {
        $this->assertSame('', SEOHelper::renderDynamicYear(null));
        $this->assertSame('', SEOHelper::renderDynamicYear(''));
    }
}

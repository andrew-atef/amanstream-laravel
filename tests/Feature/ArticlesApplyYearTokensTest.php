<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlesApplyYearTokensTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(array $attributes = []): Article
    {
        $category = Category::create([
            'name' => 'لابتوبات',
            'slug' => 'laptops',
            'description' => 'أجهزة اللابتوب',
        ]);

        return Article::create(array_merge([
            'category_id' => $category->id,
            'type' => 'blog',
            'title' => 'أفضل لابتوب 2026',
            'slug' => 'laptop-2026-'.uniqid(),
            'content' => 'تعرف على أفضل لابتوب في 2026 بقرار شراء مدروس.',
            'meta_title' => 'أفضل لابتوب 2026',
            'meta_description' => 'مراجعة أفضل لابتوب 2026 من حيث السعر والأداء.',
            'is_published' => true,
        ], $attributes));
    }

    public function test_replaces_year_in_title_seo_title_and_seo_description(): void
    {
        $article = $this->makeArticle();

        $this->artisan('articles:apply-year-tokens', ['--ids' => [(string) $article->id]])
            ->assertExitCode(0);

        $article->refresh();

        $this->assertSame('أفضل لابتوب [year]', $article->getRawOriginal('title'));
        $this->assertSame('أفضل لابتوب [year]', $article->getRawOriginal('meta_title'));
        $this->assertSame('مراجعة أفضل لابتوب [year] من حيث السعر والأداء.', $article->getRawOriginal('meta_description'));

        // Body is only rewritten when --content is explicitly passed.
        $this->assertSame('تعرف على أفضل لابتوب في 2026 بقرار شراء مدروس.', $article->getRawOriginal('content'));
    }

    public function test_rewrites_content_when_content_flag_is_passed(): void
    {
        $article = $this->makeArticle();

        $this->artisan('articles:apply-year-tokens', ['--ids' => [(string) $article->id], '--content' => true])
            ->assertExitCode(0);

        $article->refresh();

        $this->assertSame('تعرف على أفضل لابتوب في [year] بقرار شراء مدروس.', $article->getRawOriginal('content'));
    }

    public function test_does_not_touch_years_glued_to_other_digits(): void
    {
        $article = $this->makeArticle([
            'title' => 'دليل 20260 و تجربة 12026',
        ]);

        $this->artisan('articles:apply-year-tokens', ['--ids' => [(string) $article->id]])
            ->assertExitCode(0);

        $article->refresh();

        $this->assertSame('دليل 20260 و تجربة 12026', $article->getRawOriginal('title'));
    }

    public function test_replaces_arabic_indic_year_figures(): void
    {
        $article = $this->makeArticle([
            'title' => 'أفضل هاتف ٢٠٢٦',
            'meta_title' => null,
            'meta_description' => null,
        ]);

        $this->artisan('articles:apply-year-tokens', ['--ids' => [(string) $article->id]])
            ->assertExitCode(0);

        $article->refresh();

        $this->assertSame('أفضل هاتف [year]', $article->getRawOriginal('title'));
    }

    public function test_dry_run_previews_changes_without_persisting(): void
    {
        $article = $this->makeArticle();

        $this->artisan('articles:apply-year-tokens', ['--ids' => [(string) $article->id], '--dry-run' => true])
            ->expectsOutputToContain('Would update')
            ->assertExitCode(0);

        $article->refresh();

        $this->assertSame('أفضل لابتوب 2026', $article->getRawOriginal('title'));
    }

    public function test_invalid_year_is_rejected(): void
    {
        $this->artisan('articles:apply-year-tokens', ['--year' => '20'])
            ->assertExitCode(1);
    }
}

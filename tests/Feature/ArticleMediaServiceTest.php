<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\ArticleMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

class ArticleMediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function r2BaseUrl(): string
    {
        return rtrim((string) config('filesystems.disks.r2.url'), '/');
    }

    /**
     * Build a real in-memory PNG payload via GD.
     */
    private function makePng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 100, 100, imagecolorallocate($image, 200, 30, 30));

        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();

        imagedestroy($image);

        return $png;
    }

    /**
     * Create a Livewire-style temporary upload on the faked temp disk, exactly
     * like the editor produces (encoded original name embedded in the path).
     */
    private function makeTemporaryUpload(string $originalName, string $contents): TemporaryUploadedFile
    {
        Storage::fake('tmp-for-tests');

        $filename = Str::random(30)
            .'-meta'.base64_encode($originalName)
            .'-'
            .'.'.pathinfo($originalName, PATHINFO_EXTENSION);

        Storage::disk('tmp-for-tests')->put(FileUploadConfiguration::path($filename), $contents);

        return TemporaryUploadedFile::createFromLivewire($filename);
    }

    public function test_upload_and_optimize_stores_webp_and_returns_public_url(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for WebP conversion.');
        }

        Storage::fake('r2');

        $file = $this->makeTemporaryUpload('wide-photo.png', $this->makePng(2400, 600));
        $url = ArticleMediaService::uploadAndOptimize($file, null);

        $this->assertNotNull($url);
        $this->assertStringStartsWith($this->r2BaseUrl().'/articles/art-draft-', $url);
        $this->assertStringEndsWith('.webp', $url);

        $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        Storage::disk('r2')->assertExists($relativePath);

        $contents = (string) Storage::disk('r2')->get($relativePath);

        // WebP magic bytes: RIFF .... WEBP.
        $this->assertStringStartsWith('RIFF', $contents);
        $this->assertSame('WEBP', substr($contents, 8, 4));

        // Wide sources are clamped to 1920px wide, keeping the aspect ratio.
        $decoded = imagecreatefromstring((string) $contents);
        $this->assertNotFalse($decoded);
        $this->assertLessThanOrEqual(1920, imagesx($decoded));
        imagedestroy($decoded);
    }

    public function test_upload_uses_article_slug_in_filename(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for WebP conversion.');
        }

        Storage::fake('r2');

        $article = Article::create([
            'title' => 'اختبار',
            'slug' => 'test-slug',
            'content' => 'محتوى',
            'is_published' => false,
        ]);

        $file = $this->makeTemporaryUpload('pic.png', $this->makePng(100, 100));
        $url = ArticleMediaService::uploadAndOptimize($file, $article);

        $this->assertNotNull($url);
        $this->assertStringContainsString('/articles/art-test-slug-', $url);
    }

    public function test_extract_r2_images_returns_only_deduplicated_article_urls(): void
    {
        $content = implode("\n", [
            '![نسبي](/articles/relative.webp)',
            '![أ]('.$this->r2BaseUrl().'/articles/one.webp)',
            '![ب](https://bucket.r2.dev/articles/two.webp)',
            '![ج](https://cdn.example.com/articles/foreign.webp)',
            '![د](https://other.r2.dev/not-articles/three.webp)',
            '![كرر]('.$this->r2BaseUrl().'/articles/one.webp)',
        ]);

        $images = ArticleMediaService::extractR2Images($content);

        $this->assertSame([
            $this->r2BaseUrl().'/articles/one.webp',
            'https://bucket.r2.dev/articles/two.webp',
        ], $images);
    }

    public function test_extract_r2_images_handles_blank_content(): void
    {
        $this->assertSame([], ArticleMediaService::extractR2Images(null));
        $this->assertSame([], ArticleMediaService::extractR2Images('  '));
    }

    public function test_delete_from_r2_removes_only_article_objects(): void
    {
        Storage::fake('r2');

        Storage::disk('r2')->put('articles/art-keep.webp', 'webp-bytes');

        $deleted = ArticleMediaService::deleteFromR2($this->r2BaseUrl().'/articles/art-keep.webp');
        $this->assertTrue($deleted);
        Storage::disk('r2')->assertMissing('articles/art-keep.webp');

        // Non-article prefix on an R2 host is refused, not wiped.
        $this->assertFalse(ArticleMediaService::deleteFromR2('https://bucket.r2.dev/other/art.webp'));

        // Foreign URLs are never touched by GC.
        $this->assertFalse(ArticleMediaService::deleteFromR2('https://cdn.example.com/articles/foreign.webp'));
    }

    public function test_updating_content_deletes_removed_images_but_keeps_remaining_ones(): void
    {
        Storage::fake('r2');

        Storage::disk('r2')->put('articles/art-removed.webp', 'webp-bytes');
        Storage::disk('r2')->put('articles/art-kept.webp', 'webp-bytes');

        $article = Article::create([
            'title' => 'عنوان',
            'slug' => 'garbage-collection',
            'content' => '![م]('.$this->r2BaseUrl()."/articles/art-removed.webp)\n![م](".$this->r2BaseUrl().'/articles/art-kept.webp)',
            'is_published' => false,
        ]);

        $article->update([
            'content' => '![م]('.$this->r2BaseUrl().'/articles/art-kept.webp)',
        ]);

        Storage::disk('r2')->assertMissing('articles/art-removed.webp');
        Storage::disk('r2')->assertExists('articles/art-kept.webp');
    }

    public function test_non_content_update_does_not_delete_images(): void
    {
        Storage::fake('r2');

        Storage::disk('r2')->put('articles/art-intact.webp', 'webp-bytes');

        $article = Article::create([
            'title' => 'عنوان',
            'slug' => 'no-garbage',
            'content' => '![م]('.$this->r2BaseUrl().'/articles/art-intact.webp)',
            'is_published' => false,
        ]);

        $article->update(['title' => 'عنوان محدث']);

        Storage::disk('r2')->assertExists('articles/art-intact.webp');
    }

    public function test_deleting_article_deletes_all_its_r2_images(): void
    {
        Bus::fake();
        Storage::fake('r2');

        Storage::disk('r2')->put('articles/art-a.webp', 'webp-bytes');
        Storage::disk('r2')->put('articles/art-b.webp', 'webp-bytes');

        $article = Article::create([
            'title' => 'عنوان',
            'slug' => 'delete-all',
            'content' => '![أ]('.$this->r2BaseUrl()."/articles/art-a.webp)\n![ب](".$this->r2BaseUrl().'/articles/art-b.webp)',
            'is_published' => false,
        ]);

        $article->delete();

        Storage::disk('r2')->assertMissing('articles/art-a.webp');
        Storage::disk('r2')->assertMissing('articles/art-b.webp');
    }
}

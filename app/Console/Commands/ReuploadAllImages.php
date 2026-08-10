<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Amazon\AmazonUrlDataFetcher;
use App\Services\ImageUploaderService;
use Illuminate\Console\Command;

class ReuploadAllImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:reupload-all
                            {--force : Force re-fetching raw images from Amazon scraper}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch raw Amazon images, apply AmanStream watermark, convert to WebP and upload to R2 with the custom domain.';

    public function __construct(private readonly AmazonUrlDataFetcher $fetcher)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $total = Product::query()->where('is_active', true)->count();

        if ($total === 0) {
            $this->warn('No active products to process.');

            return self::SUCCESS;
        }

        $this->info("Starting bulk image re-fetch, watermarking & R2 upload for {$total} active products...");

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $force = (bool) $this->option('force');

        Product::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunk(100, function ($products) use ($force, &$success, &$failed, &$skipped) {
                foreach ($products as $product) {
                    $this->line("Processing [{$product->asin}] {$product->title}...");

                    try {
                        $rawImageUrl = $this->resolveRawImageUrl($product, $force);

                        if (blank($rawImageUrl)) {
                            $this->warn("  -> Skipped: Could not find raw Amazon image URL for ASIN {$product->asin}");
                            $skipped++;

                            continue;
                        }

                        // ImageUploaderService::uploadToR2 mirrors the raw image URL
                        // to R2 exactly once and stores the permanent public URL.
                        $uploadedUrl = ImageUploaderService::uploadToR2($product, $rawImageUrl);

                        if ($uploadedUrl) {
                            $this->info("  -> Success: Uploaded with watermark -> {$uploadedUrl}");
                            $success++;
                        } else {
                            $this->error("  -> Failed: Upload to R2 failed for ASIN {$product->asin}");
                            $failed++;
                        }
                    } catch (\Throwable $e) {
                        $this->error("  -> Error: {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->newLine();
        $this->info("Bulk image processing complete! Success: {$success}, Failed: {$failed}, Skipped: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Decide which source image to mirror to R2.
     *
     * When the stored URL is already our R2 public domain (or a legacy
     * *.r2.dev bucket URL) the previously uploaded object was either deleted
     * from the bucket or the domain changed, so we must re-fetch the raw
     * Amazon image. The same happens with --force. Otherwise the stored
     * external URL can be used as-is.
     */
    protected function resolveRawImageUrl(Product $product, bool $force): ?string
    {
        $stored = (string) $product->image_url;
        $publicUrl = rtrim((string) config('filesystems.disks.r2.url'), '/');

        $isAlreadyR2 = str_contains($stored, 'r2.dev')
            || (filled($publicUrl) && str_starts_with($stored, $publicUrl));

        if (! $force && $stored !== '' && ! $isAlreadyR2) {
            return $stored;
        }

        $amazonUrl = "https://www.amazon.eg/dp/{$product->asin}";
        $data = $this->fetcher->fetch($amazonUrl);

        return $data['image_url'] ?? null;
    }
}
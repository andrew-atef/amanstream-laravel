<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ImageUploaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UploadProductImageToR2Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly int $productId,
        public readonly string $imageUrl,
    ) {}

    /**
     * Mirror a product's external image onto Cloudflare R2 asynchronously.
     *
     * The service swallows download/conversion failures and logs them, so a
     * transient network error never crashes the queue worker.
     */
    public function handle(): void
    {
        $product = Product::find($this->productId);

        if ($product === null) {
            return;
        }

        ImageUploaderService::uploadToR2($product, $this->imageUrl);
    }
}
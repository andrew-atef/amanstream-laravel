<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupLegacyEmptyAsins extends Command
{
    protected $signature = 'products:cleanup-legacy-empty-asins {--dry-run : List what would be modified without executing}';

    protected $description = 'Safely re-link articles to real-ASIN twin products and delete legacy empty-ASIN product rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $skipped = 0;
        $relinkedArticles = 0;

        $legacy = Product::query()
            ->whereRaw('TRIM(COALESCE(asin, \'\')) = \'\'')
            ->get();

        if ($legacy->isEmpty()) {
            $this->line('No empty-ASIN products found. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($legacy as $product) {
            $realAsin = $this->extractAsin((string) $product->affiliate_url);

            if ($realAsin === '' || $realAsin === null) {
                $this->warn(sprintf(
                    'Skipping #%d: cannot derive real ASIN from its URL (%s)',
                    $product->id,
                    mb_substr((string) $product->affiliate_url, 0, 60)
                ));
                $skipped++;

                continue;
            }

            $twin = Product::query()
                ->whereRaw('UPPER(asin) = ?', [$realAsin])
                ->where('id', '!=', $product->id)
                ->first();

            if (! $twin) {
                $this->warn(sprintf(
                    'Skipping #%d: ASIN %s has no real-ASIN twin in the DB',
                    $product->id,
                    $realAsin
                ));
                $skipped++;

                continue;
            }

            $articleCount = Article::query()->where('product_id', $product->id)->count();

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] WOULD delete legacy #%d (asin=[%s]) -> keeping twin #%d (asin=%s), re-linking %d linked article(s)',
                    $product->id,
                    $product->asin,
                    $twin->id,
                    $realAsin,
                    $articleCount
                ));
                $deleted++;
                $relinkedArticles += $articleCount;

                continue;
            }

            DB::transaction(function () use ($product, $twin, $articleCount, &$relinkedArticles): void {
                // Safely re-link articles to the real-ASIN twin product instead of deleting published content!
                if ($articleCount > 0) {
                    Article::query()
                        ->where('product_id', $product->id)
                        ->update(['product_id' => $twin->id]);

                    $relinkedArticles += $articleCount;
                    $this->info("  re-linked {$articleCount} article(s) to twin #{$twin->id}");
                }

                $product->delete();
            });

            $this->info(sprintf('Deleted legacy product #%d (empty ASIN), kept twin #%d (ASIN=%s)', $product->id, $twin->id, $realAsin));
            $deleted++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry-run complete: {$deleted} legacy product(s) would be deleted, {$relinkedArticles} article(s) would be re-linked, {$skipped} skipped."
            : "Done: deleted {$deleted} legacy product(s), re-linked {$relinkedArticles} article(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function extractAsin(string $url): string
    {
        if (preg_match('/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        return '';
    }
}
<?php

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Models\Product;
use App\Services\Amazon\Contracts\AmazonProductDataFetcher;
use Illuminate\Console\Command;

class SyncAmazonPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazon:sync-prices
                            {--dry-run : Simulate the sync without persisting changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronise product pricing, stock and ratings with Amazon and refresh Google freshness signals.';

    public function __construct(private readonly AmazonProductDataFetcher $fetcher)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $total = Product::count();

        if ($total === 0) {
            $this->warn('No products to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$total} product(s)...");

        $synced = 0;
        $refreshed = 0;
        $dryRun = (bool) $this->option('dry-run');

        // Sync every product regardless of stock: an out-of-stock item can come
        // back in stock on Amazon, and only a fresh fetch can revive it.
        Product::query()
            ->with('articles')
            ->chunk(100, function ($products) use ($dryRun, &$synced, &$refreshed) {
                foreach ($products as $product) {
                    try {
                        $live = $this->fetcher->fetch($product);

                        $this->info("  [{$product->asin}] {$product->title}");

                        $shouldRefresh = ! $dryRun && $product->hasMaterialPriceChange((float) $live['price']);

                        $this->applyMarketData($product, $live, $dryRun);
                        $synced++;

                        if ($shouldRefresh) {
                            $this->refreshAssociatedArticles($product, $dryRun);
                            $refreshed += $product->articles->count();
                        }
                    } catch (\Throwable $e) {
                        $this->error("  Failed to sync {$product->asin}: {$e->getMessage()}");
                    }
                }
            });

        $this->newLine();
        $this->info("Done. Products synced: {$synced}.".($dryRun ? ' [dry-run, no changes persisted]' : ''));
        $this->info("Article updated_at refreshed for Google freshness: {$refreshed}.");

        return self::SUCCESS;
    }

    /**
     * Persist (or report) the fetched marketplace data onto the model.
     */
    protected function applyMarketData(Product $product, array $live, bool $dryRun): void
    {
        $previousPrice = (float) $product->price;
        $livePrice = (float) ($live['price'] ?? $product->price);
        $inStock = (bool) ($live['in_stock'] ?? $product->in_stock);

        // An out-of-stock page may report price 0 — treat it as a real SUCCESS
        // state and keep the last known good price so badges/history stay sane.
        $finalPrice = ($inStock === false && $livePrice <= 0)
            ? ($previousPrice > 0 ? $previousPrice : 0)
            : $livePrice;

        $product->price = $finalPrice;
        $product->in_stock = $inStock;
        $product->rating = (float) ($live['rating'] ?? $product->rating);
        $product->review_count = (int) ($live['review_count'] ?? $product->review_count);
        $product->last_synced_at = now();
        $product->sync_status = Product::SYNC_STATUS_SYNCED;
        $product->sync_attempts = 0;
        $product->last_sync_error = null;

        if (! $dryRun) {
            // Golden rule: log a history row + refresh the memoized range/JSON
            // window ONLY when the price actually moved on a buyable item.
            if ($inStock && $finalPrice > 0) {
                $product->recordPriceHistory($finalPrice, now(), $previousPrice);
            }

            $product->save();
        }
    }

    /**
     * Touch every associated published article to refresh Google freshness signals
     * and dispatch a Cloudflare cache purge for all affected URLs.
     */
    protected function refreshAssociatedArticles(Product $product, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('      > Material price change; would refresh '.$product->articles->count().' article(s).');

            return;
        }

        $articles = $product->articles()
            ->where('is_published', true)
            ->get();

        $urls = [
            url('/'),
            url('/sitemap.xml'),
        ];

        foreach ($articles as $article) {
            $article->touch();
            $urls[] = route('articles.show', $article->slug, true);
        }

        PurgeCloudflareCacheJob::dispatch(array_unique($urls));

        $this->line('      > Material price change; refreshed '.$articles->count().' article(s) + purged cache.');
    }
}

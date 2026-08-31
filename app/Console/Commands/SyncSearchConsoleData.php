<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;

class SyncSearchConsoleData extends Command
{
    protected $signature = 'gsc:sync-analytics {--days=90 : How many days of daily history to fetch}';

    protected $description = 'Fetch daily Google Search Console organic metrics and upsert into article_search_analytics.';

    public function handle(GoogleSearchConsoleService $gsc): int
    {
        $days = (int) $this->option('days');

        if ($days < 1 || $days > 365) {
            $this->error('Days must be between 1 and 365.');

            return self::FAILURE;
        }

        $this->info("🔍 Fetching {$days}-day daily search analytics from Google Search Console...");
        $this->newLine();

        $upserted = $gsc->syncHistoricalSearchAnalytics($days);

        if ($upserted === 0) {
            $this->warn('⚠  No data returned. Check credentials and site URL config.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("✅ Sync complete: {$upserted} daily rows upserted for the last {$days} days.");

        return self::SUCCESS;
    }
}

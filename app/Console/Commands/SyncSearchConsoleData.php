<?php

namespace App\Console\Commands;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;

class SyncSearchConsoleData extends Command
{
    protected $signature = 'gsc:sync-analytics {--days=90 : How many days of daily history to fetch} {--verbose-diagnostics : Show detailed API diagnostic info}';

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

        $result = $gsc->syncHistoricalSearchAnalytics($days);

        if ($result['error'] !== null) {
            $this->error('❌ '.$result['error']);
            $this->newLine();

            if ($this->option('verbose-diagnostics') && $result['diagnostics'] !== []) {
                $this->warn('── Diagnostic Info ──');
                foreach ($result['diagnostics'] as $key => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }
                    $this->line("  {$key}: {$value}");
                }
                $this->newLine();
            }

            // Always show actionable hints
            $this->warn('── Troubleshooting ──');
            $this->line('  1. Verify credentials file exists and has client_email + private_key');
            $this->line('  2. Open Google Search Console → Settings → Users and permissions');
            $this->line('  3. Add the service account email as a "Full" user');
            $this->line('  4. Ensure GOOGLE_GSC_SITE_URL matches your GSC property exactly');
            $this->line('     (e.g. "https://www.amanprice.tech/" with trailing slash)');
            $this->newLine();

            return self::SUCCESS;
        }

        $upserted = $result['upserted'];

        if ($upserted === 0) {
            $this->warn('⚠  Sync completed but no rows were upserted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("✅ Sync complete: {$upserted} daily rows upserted for the last {$days} days.");

        if ($this->option('verbose-diagnostics') && $result['diagnostics'] !== []) {
            $this->newLine();
            $this->warn('── Diagnostic Info ──');
            foreach ($result['diagnostics'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }

        return self::SUCCESS;
    }
}

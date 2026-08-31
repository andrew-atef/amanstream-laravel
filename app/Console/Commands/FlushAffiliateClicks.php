<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FlushAffiliateClicks extends Command
{
    protected $signature = 'affiliate:flush-clicks';

    protected $description = 'Flush buffered in-memory affiliate click counters into SQLite in a single batch transaction.';

    public function handle(): int
    {
        $activeAsins = (array) Cache::get('pending_clicked_asins_list', []);

        if ($activeAsins === []) {
            $this->info('No pending clicks to flush.');

            return self::SUCCESS;
        }

        $today = date('Y-m-d');
        $flushed = 0;
        $totalClicks = 0;

        DB::transaction(function () use ($activeAsins, $today, &$flushed, &$totalClicks) {
            foreach ($activeAsins as $asin) {
                $count = (int) Cache::pull('pending_clicks_asin_'.$asin, 0);

                if ($count <= 0) {
                    continue;
                }

                Product::whereRaw('UPPER(asin) = ?', [$asin])
                    ->increment('clicks_count', $count);

                DB::table('affiliate_daily_clicks')->upsert(
                    [
                        'asin' => $asin,
                        'date' => $today,
                        'clicks' => $count,
                    ],
                    ['asin', 'date'],
                    ['clicks' => DB::raw("clicks + {$count}")]
                );

                $flushed++;
                $totalClicks += $count;
            }
        });

        Cache::forget('pending_clicked_asins_list');
        Cache::forget('pending_clicks_total');
        Cache::forget('pending_clicks_today_'.$today);

        $this->info("Flushed {$totalClicks} clicks across {$flushed} products.");

        return self::SUCCESS;
    }
}

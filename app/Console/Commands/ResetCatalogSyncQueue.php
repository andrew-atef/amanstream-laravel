<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ResetCatalogSyncQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:reset-sync-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-queue products whose last catalog sync is older than 6 hours back to pending status.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subHours(Product::SYNC_RECYCLE_HOURS);

        $affected = Product::query()
            ->where('is_active', true)
            ->where('last_synced_at', '<', $cutoff)
            ->whereIn('sync_status', [Product::SYNC_STATUS_SYNCED, Product::SYNC_STATUS_FAILED])
            ->update([
                'sync_status' => Product::SYNC_STATUS_PENDING,
                'sync_attempts' => 0,
                'last_sync_error' => null,
            ]);

        $this->info("Reset {$affected} product(s) to pending (last synced before {$cutoff}).");

        return self::SUCCESS;
    }
}

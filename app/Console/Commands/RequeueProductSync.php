<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class RequeueProductSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:requeue {ids* : Product id(s) to flip back to pending catalog sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instantly requeue one or more product(s) as pending so the scraper pulls their data automatically, without waiting for the 6-hour cron.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $requeued = 0;
        $missing = 0;
        $inactive = 0;

        foreach ($this->argument('ids') as $rawId) {
            $id = (int) $rawId;
            $product = Product::query()->find($id);

            if ($product === null) {
                $this->warn("Product #{$id} not found.");
                $missing++;

                continue;
            }

            if (! $product->is_active) {
                $this->warn("Product #{$id} is inactive — it will only be pulled once it is re-activated.");
                $inactive++;
            }

            // Clearing last_synced_at puts the product at the FRONT of the
            // pendingForCatalogSync queue (NULLS FIRST), so the scraper's next
            // batch pull picks it up immediately.
            $product->update([
                'sync_status' => Product::SYNC_STATUS_PENDING,
                'sync_attempts' => 0,
                'last_sync_error' => null,
                'last_synced_at' => null,
            ]);

            $this->line("Product #{$id} requeued as pending (front of queue).");
            $requeued++;
        }

        $this->newLine();
        $this->info("Done: {$requeued} requeued, {$missing} missing, {$inactive} inactive.");

        return self::SUCCESS;
    }
}

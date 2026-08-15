<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseProductAsins extends Command
{
    protected $signature = 'products:diagnose-asins {asin? : Only inspect this ASIN}';

    protected $description = 'Diagnose ASIN duplicates / empty values that break product edit saves';

    public function handle(): int
    {
        $target = $this->argument('asin');

        $this->info('=== Empty-ASIN products (legacy bulk imports) ===');
        $empties = Product::query()
            ->whereRaw('TRIM(COALESCE(asin, \'\')) = \'\'')
            ->get(['id', 'title', 'sync_status', 'created_at']);

        if ($empties->isEmpty()) {
            $this->line('None.');
        } else {
            foreach ($empties as $p) {
                $this->line(sprintf('  #%d | %s | status=%s | created=%s',
                    $p->id, mb_substr((string) $p->title, 0, 50), $p->sync_status, $p->created_at));
            }
        }

        $this->newLine();
        $this->info('=== Duplicate ASINs (case-insensitive) ===');
        $dupes = DB::table('products')
            ->selectRaw('UPPER(asin) AS upper_asin, COUNT(*) AS cnt')
            ->whereRaw('TRIM(COALESCE(asin, \'\')) <> \'\'')
            ->groupByRaw('UPPER(asin)')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')
            ->get();

        if ($dupes->isEmpty()) {
            $this->line('None.');
        } else {
            foreach ($dupes as $d) {
                $this->error(sprintf('  ASIN %s -> %d rows', $d->upper_asin, $d->cnt));
                Product::query()
                    ->whereRaw('UPPER(asin) = ?', [$d->upper_asin])
                    ->get(['id', 'title', 'is_active', 'sync_status', 'created_at'])
                    ->each(fn ($p) => $this->line(sprintf(
                        '    #%d | active=%s | sync=%s | %s | %s',
                        $p->id,
                        $p->is_active ? 'yes' : 'NO',
                        $p->sync_status,
                        $p->created_at,
                        mb_substr((string) $p->title, 0, 60)
                    )));
            }
        }

        if ($target !== null) {
            $this->newLine();
            $this->info("=== Products matching ASIN $target ====");
            Product::query()
                ->whereRaw('UPPER(asin) = ?', [strtoupper($target)])
                ->get(['id', 'title', 'asin', 'is_active', 'sync_status', 'created_at', 'updated_at'])
                ->each(fn ($p) => $this->line(sprintf(
                    '  #%d | asin=[%s] | active=%s | sync=%s | created=%s | updated=%s | %s',
                    $p->id,
                    $p->asin,
                    $p->is_active ? 'yes' : 'NO',
                    $p->sync_status,
                    $p->created_at,
                    $p->updated_at,
                    mb_substr((string) $p->title, 0, 60)
                )));
        }

        return self::SUCCESS;
    }
}
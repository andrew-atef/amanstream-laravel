<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade the originally deployed deep-scrape columns to the editorial
     * spec contract:
     *
     *  1. Rename `deep_data_json` → `deep_specs_json` (SQLite RENAME COLUMN).
     *  2. Clear stale snapshots/diffs captured with the OLD payload vocabulary
     *     (which also carried pricing noise) so the first submission under the
     *     new contract is a clean baseline instead of a one-time noise alert.
     *  3. Settle every involved row (except those still pending) back to
     *     'synced': their old diff log is gone, so a dangling 'specs_changed'
     *     or the legacy 'updated_with_diff' value would show a warning badge
     *     with nothing behind it.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'deep_data_json')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('deep_data_json', 'deep_specs_json');
        });

        DB::statement(
            "UPDATE products
                SET deep_specs_json = NULL,
                    spec_diff_json  = NULL,
                    deep_scrape_status = 'synced'
              WHERE deep_scrape_status IN ('synced', 'updated_with_diff', 'specs_changed')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('deep_specs_json', 'deep_data_json');
        });
    }
};

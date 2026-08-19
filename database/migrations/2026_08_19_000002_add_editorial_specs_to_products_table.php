<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editorial deep-scrape columns (qualitative spec facts only).
     *
     * NOTE: `raw_amazon_data` already lives in its own dedicated migration
     * (2026_08_17_000002_add_raw_amazon_data_to_products_table.php), so it is
     * intentionally NOT re-added here to avoid a duplicate-column error on a
     * fresh migrate.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('deep_scrape_status', 32)->default('idle')->after('sync_status');
            $table->json('deep_specs_json')->nullable()->after('deep_scrape_status');
            $table->json('spec_diff_json')->nullable()->after('deep_specs_json');
            $table->timestamp('deep_scraped_at')->nullable()->after('spec_diff_json');

            $table->index('deep_scrape_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['deep_scrape_status']);
            $table->dropColumn(['deep_scrape_status', 'deep_specs_json', 'spec_diff_json', 'deep_scraped_at']);
        });
    }
};

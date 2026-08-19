<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('deep_scrape_status', 32)->default('idle')->after('sync_status');
            $table->json('deep_data_json')->nullable()->after('deep_scrape_status');
            $table->json('spec_diff_json')->nullable()->after('deep_data_json');
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
            $table->dropColumn(['deep_scrape_status', 'deep_data_json', 'spec_diff_json', 'deep_scraped_at']);
        });
    }
};

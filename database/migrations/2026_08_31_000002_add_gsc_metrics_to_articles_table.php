<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedInteger('gsc_clicks_30d')->default(0)->index();
            $table->unsignedInteger('gsc_impressions_30d')->default(0)->index();
            $table->decimal('gsc_ctr_30d', 5, 2)->default(0.00);
            $table->decimal('gsc_position_30d', 4, 1)->default(0.0);
            $table->timestamp('gsc_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'gsc_clicks_30d',
                'gsc_impressions_30d',
                'gsc_ctr_30d',
                'gsc_position_30d',
                'gsc_synced_at',
            ]);
        });
    }
};

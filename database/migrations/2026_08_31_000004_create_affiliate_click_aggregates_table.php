<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('clicks_count')->default(0)->index()->after('review_count');
        });

        Schema::create('affiliate_daily_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 20)->index();
            $table->date('date')->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['asin', 'date'], 'uq_asin_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_daily_clicks');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('clicks_count');
        });
    }
};

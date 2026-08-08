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
            $table->decimal('lowest_price', 10, 2)->nullable()->after('original_price');
            $table->decimal('highest_price', 10, 2)->nullable()->after('lowest_price');
            $table->json('price_history_json')->nullable()->after('highest_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price_history_json', 'highest_price', 'lowest_price']);
        });
    }
};

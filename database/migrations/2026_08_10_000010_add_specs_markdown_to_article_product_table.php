<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the free-form Markdown specs field; specs_json is kept for legacy data.
     */
    public function up(): void
    {
        Schema::table('article_product', function (Blueprint $table) {
            $table->longText('specs_markdown')->nullable()->after('specs_json');
        });
    }

    public function down(): void
    {
        Schema::table('article_product', function (Blueprint $table) {
            $table->dropColumn('specs_markdown');
        });
    }
};
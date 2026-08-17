<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a content-type discriminator to articles so admins can separate
     * "Product Reviews / Comparisons" (type=review, linked to products) from
     * "General Blog Posts / Guides" (type=blog, editorial content served under
     * /blog). Existing rows default to 'review' — backward compatible.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('type')->default('review')->index();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
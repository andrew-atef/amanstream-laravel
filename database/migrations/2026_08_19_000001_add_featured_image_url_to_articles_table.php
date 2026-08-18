<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom editorial featured thumbnail / cover image, stored on Cloudflare
     * R2 and used for card covers, the article hero banner and OpenGraph.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('featured_image_url')->nullable()->after('meta_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('featured_image_url');
        });
    }
};

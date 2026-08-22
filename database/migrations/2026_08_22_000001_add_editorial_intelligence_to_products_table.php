<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->longText('facebook_insights')->nullable()->after('raw_amazon_data');
            $table->longText('video_transcripts')->nullable()->after('facebook_insights');
            $table->longText('catalog_manual')->nullable()->after('video_transcripts');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['facebook_insights', 'video_transcripts', 'catalog_manual']);
        });
    }
};

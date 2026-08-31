<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_search_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('page_url')->index();
            $table->date('date')->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 5, 2)->default(0.00);
            $table->decimal('position', 4, 1)->default(0.0);
            $table->unique(['page_url', 'date'], 'uq_page_date');
            $table->index(['article_id', 'date'], 'idx_article_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_search_analytics');
    }
};

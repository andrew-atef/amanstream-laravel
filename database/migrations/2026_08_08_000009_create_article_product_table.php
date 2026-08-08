<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table linking an article to the comparison/round-up products it
     * reviews, carrying per-product ordering, badges, quick verdicts and specs.
     */
    public function up(): void
    {
        Schema::create('article_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('badge_label')->nullable();
            $table->text('quick_verdict')->nullable();
            $table->json('specs_json')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_product');
    }
};
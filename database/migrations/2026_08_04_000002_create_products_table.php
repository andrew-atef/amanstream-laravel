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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('asin')->unique();
            $table->string('brand')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('rating', 3, 2)->default(4.5);
            $table->integer('review_count')->default(100);
            $table->text('affiliate_url');
            $table->text('image_url')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

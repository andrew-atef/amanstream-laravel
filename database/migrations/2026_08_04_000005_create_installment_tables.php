<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('code')->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('months');
            $table->decimal('interest_rate', 5, 2)->default(0.00);
            $table->decimal('admin_fee_percent', 5, 2)->default(0.00);
            $table->decimal('min_order_amount', 10, 2)->default(500.00);
            $table->boolean('is_zero_interest')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
        Schema::dropIfExists('banks');
    }
};

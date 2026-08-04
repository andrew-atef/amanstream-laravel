<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('supports_installment');
            $table->string('platform', 20)->default('amazon')->after('is_active');
            $table->decimal('original_price', 10, 2)->nullable()->after('price');
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending')->after('last_synced_at');
            $table->unsignedInteger('sync_attempts')->default(0)->after('sync_status');
            $table->text('last_sync_error')->nullable()->after('sync_attempts');

            $table->index('last_synced_at');
            $table->index('sync_status');
            $table->index(['sync_status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sync_status', 'last_synced_at']);
            $table->dropIndex(['sync_status']);
            $table->dropIndex(['last_synced_at']);
            $table->dropColumn(['is_active', 'platform', 'original_price', 'sync_status', 'sync_attempts', 'last_sync_error']);
        });
    }
};

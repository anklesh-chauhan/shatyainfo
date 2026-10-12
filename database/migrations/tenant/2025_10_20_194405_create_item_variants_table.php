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
        Schema::table('item_masters', function (Blueprint $table) {
            // Add parent-child (variant) relationship
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('item_masters')
                ->onDelete('cascade');

            // Add variant details
            $table->string('variant_name')->nullable()->after('item_name');
            $table->string('sku')->nullable()->unique()->after('variant_name');

            // Flag to indicate if the item has variants
            $table->boolean('has_variants')->default(false)->after('sku');

            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_masters', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'variant_name', 'sku', 'has_variants']);
        });
    }
};

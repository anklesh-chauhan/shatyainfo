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
            $table->string('item_type')->nullable()->after('item_name')->index();
        });

        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->string('sgip_stock_source')->default('sample_issue');
            $table->foreignId('sgip_hq_location_id')
                ->nullable()
                ->constrained('location_masters')
                ->nullOnDelete();
        });

        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->foreignId('sample_issue_id')
                ->nullable()
                ->constrained('sample_issues')
                ->nullOnDelete();
            $table->foreignId('inventory_source_location_id')
                ->nullable()
                ->constrained('location_masters')
                ->nullOnDelete();
            $table->timestamp('inventory_posted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_issue_id');
            $table->dropConstrainedForeignId('inventory_source_location_id');
            $table->dropColumn('inventory_posted_at');
        });

        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sgip_hq_location_id');
            $table->dropColumn('sgip_stock_source');
        });

        Schema::table('item_masters', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};

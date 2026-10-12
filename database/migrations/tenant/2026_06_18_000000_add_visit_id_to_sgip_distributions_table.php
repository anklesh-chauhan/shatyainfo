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
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->foreignId('visit_id')
                ->nullable()
                ->after('sales_tour_plan_id')
                ->constrained('visits')
                ->nullOnDelete()
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id');
        });
    }
};

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
        Schema::table('sales_tour_plan_details', function (Blueprint $table) {
            $table->foreignId('visit_type_id')->nullable()->constrained('visit_types')->cascadeOnDelete()->after('patch_ids');
            $table->json('visit_purpose_ids')->nullable()->after('visit_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_tour_plan_details', function (Blueprint $table) {
            $table->dropForeign(['visit_type_id']);
            $table->dropColumn('visit_type_id');
            $table->dropColumn('visit_purpose_ids');
        });
    }
};

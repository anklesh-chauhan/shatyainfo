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
        Schema::create('sales_tour_plan_detail_visit_purpose', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_tour_plan_detail_id');
            $table->unsignedBigInteger('visit_purpose_id');

            // Short, custom FK names to avoid MySQL 64-char limit
            $table->foreign(
                'sales_tour_plan_detail_id',
                'stpdvp_stpd_id_fk'
            )->references('id')
                ->on('sales_tour_plan_details')
                ->cascadeOnDelete();

            $table->foreign(
                'visit_purpose_id',
                'stpdvp_vp_id_fk'
            )->references('id')
                ->on('visit_purposes')
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_tour_plan_detail_visit_purpose');
    }
};

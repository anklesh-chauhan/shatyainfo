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
        Schema::create('visit_preferences', function (Blueprint $table) {
            $table->id();

            /* ===============================
             | Visit Flow Controls
             =============================== */
            $table->boolean('enable_check_in')->default(true);
            $table->boolean('enable_check_out')->default(true);
            $table->boolean('enforce_check_in_before_check_out')->default(true);
            $table->boolean('allow_manual_time_edit')->default(false);
            $table->boolean('enable_auto_checkout')->default(false);
            $table->time('auto_checkout_time')->nullable();

            /* ===============================
             | Proof & Compliance
             =============================== */
            $table->boolean('require_check_in_image')->default(false);
            $table->boolean('require_check_out_image')->default(false);
            $table->boolean('require_general_visit_image')->default(false);
            $table->boolean('require_gps')->default(true);
            $table->integer('geo_fence_radius_meters')->nullable();

            /* ===============================
             | Visit Duration Rules
             =============================== */
            $table->boolean('enforce_minimum_duration')->default(false);
            $table->integer('minimum_duration_minutes')->nullable();

            /* ===============================
             | Dynamic Field Rules
             | visible / required / editable
             =============================== */
            $table->json('field_rules')->nullable();

            /* ===============================
            | Other Preferences
            =============================== */
            $table->boolean('allow_rescheduling')->default(true);
            $table->boolean('allow_cancellation')->default(true);
            $table->boolean('require_visit_outcome')->default(false);

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_preferences');
    }
};

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
        Schema::create('account_master_g_s_t_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_master_id')->constrained()->cascadeOnDelete();
            $table->string('gst_number')->unique();
            $table->string('state_name');
            $table->string('state_code');
            $table->string('gst_type'); // e.g., "Regular", "Composition", "SEZ", etc.
            $table->string('gst_status')->default('active'); // e.g., "active", "inactive"
            $table->string('pan_number')->nullable(); // Pan number associated with the GST
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_master_g_s_t_details');
    }
};

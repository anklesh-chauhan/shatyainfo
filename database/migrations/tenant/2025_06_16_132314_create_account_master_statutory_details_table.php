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
        Schema::create('account_master_statutory_details', function (Blueprint $table) {
            $table->id();
            $table->string('tan_number')->nullable(); // TAN number for TDS purposes
            $table->string('cin')->nullable(); // Corporate Identification Number
            $table->string('tds_parameters')->nullable();
            $table->string('tds_section')->nullable(); // TDS section applicable
            $table->string('tds_rate')->nullable(); // TDS rate applicable
            $table->string('tds_type')->nullable(); // e.g., "Salary", "Contractor", etc.
            $table->string('tds_status')->default('active'); // e.g., "active", "inactive"
            $table->string('is_tds_deduct')->nullable();  // Whether TDS is deducted or not
            $table->string('is_tds_compulsory')->nullable(); // Whether TDS is compulsory or not
            $table->string('tds_remark')->nullable(); // Remarks related to TDS
            $table->foreignId('account_master_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('account_master_statutory_details');
    }
};

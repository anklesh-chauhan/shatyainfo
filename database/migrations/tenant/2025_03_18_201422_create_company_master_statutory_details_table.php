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
        Schema::create('company_master_statutory_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_master_id')->nullable()->constrained('company_masters')->onDelete('cascade');
            $table->integer('credit_days')->nullable();
            $table->decimal('credit_limit', 10, 2)->nullable();
            $table->string('cin')->nullable();
            $table->string('tds_parameters')->nullable();
            $table->boolean('is_tds_deduct')->default(false);
            $table->boolean('is_tds_compulsory')->default(false);
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
        Schema::dropIfExists('company_master_statutory_details');
    }
};

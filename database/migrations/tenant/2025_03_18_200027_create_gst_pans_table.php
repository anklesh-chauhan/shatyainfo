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
        Schema::create('gst_pans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_master_id')->nullable()->constrained('company_masters')->onDelete('cascade'); // Added relation
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('address_id')->constrained('addresses')->onDelete('cascade');
            $table->string('pan_number');
            $table->string('gst_number');
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
        Schema::dropIfExists('gst_pans');
    }
};

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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique(); // Add lead_code
            $table->date('transaction_date')->nullable(); // Add date field
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('contact_detail_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('address_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('lead_source_id')->nullable()->constrained()->onDelete('set null');
            $table->morphs('status');
            $table->foreignId('rating_type_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->text('description')->nullable();
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
        Schema::dropIfExists('leads');
    }
};

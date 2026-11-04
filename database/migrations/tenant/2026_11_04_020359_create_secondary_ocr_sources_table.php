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
        Schema::create('secondary_ocr_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stockist_id')->constrained('account_masters')->restrictOnDelete();
            $table->foreignId('territory_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('secondary_sales_statement_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('period', 7);
            $table->string('source_path');
            $table->string('source_sha256', 64);
            $table->string('extension', 8);
            $table->unique(['stockist_id', 'source_sha256', 'period'], 'secondary_ocr_source_unique');
            $table->string('status', 30);
            $table->longText('extracted_text')->nullable();
            $table->json('tokens')->nullable();
            $table->decimal('mean_confidence', 5, 2)->nullable();
            $table->text('error')->nullable();
            $table->text('mapping_note')->nullable();
            $table->json('mapping_history')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secondary_ocr_sources');
    }
};

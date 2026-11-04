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
        Schema::create('secondary_sales_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secondary_sales_statement_id')->constrained()->restrictOnDelete();
            $table->foreignId('replacement_statement_id')->nullable()->constrained('secondary_sales_statements')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 30)->default('pending_review');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->json('original_summary');
            $table->json('replacement_summary')->nullable();
            $table->json('released_groups')->nullable();
            $table->json('released_adjustments')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['secondary_sales_statement_id', 'status'], 'secondary_correction_statement_index');
            $table->index(['reviewer_id', 'status']);
        });
        Schema::table('secondary_sales_statements', function (Blueprint $table): void {
            $table->foreignId('replaces_statement_id')->nullable()->after('source_sha256')->constrained('secondary_sales_statements')->restrictOnDelete();
            $table->foreignId('reversed_by_correction_id')->nullable()->after('replaces_statement_id')->constrained('secondary_sales_corrections')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secondary_sales_statements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by_correction_id');
            $table->dropConstrainedForeignId('replaces_statement_id');
            $table->dropColumn('reversed_at');
        });
        Schema::dropIfExists('secondary_sales_corrections');
    }
};

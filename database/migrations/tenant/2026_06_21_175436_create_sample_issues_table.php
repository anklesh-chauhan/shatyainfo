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
        Schema::create('sample_issues', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('sample_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('location_masters');
            $table->foreignId('to_location_id')->constrained('location_masters');
            $table->date('issue_date');
            $table->string('status')->default('draft');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['sample_request_id', 'status']);
            $table->index(['to_location_id', 'issue_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sample_issues');
    }
};

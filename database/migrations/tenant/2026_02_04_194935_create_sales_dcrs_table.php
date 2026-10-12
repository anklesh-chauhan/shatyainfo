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
        Schema::create('sales_dcrs', function (Blueprint $table) {
            $table->id();
            $table->date('dcr_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_tour_plan_id')->nullable()->nullOnDelete();
            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->decimal('total_expense', 10, 2)->default(0);
            $table->decimal('total_expense_approved', 10, 2)->default(0);
            $table->decimal('total_expense_rejected', 10, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->text('rejected_remarks')->nullable();
            $table->text('approved_remarks')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('territories');
            $table->json('route')->nullable();
            $table->foreignId('sales_person_id')->nullable()->constrained('users');
            $table->foreignId('sales_manager_id')->nullable()->constrained('users');
            $table->decimal('distance_covered', 10, 2)->default(0);
            $table->decimal('duration', 10, 2)->default(0);
            $table->decimal('visits_count', 10, 2)->default(0);
            $table->decimal('orders_count', 10, 2)->default(0);
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
        Schema::dropIfExists('sales_dcrs');
    }
};

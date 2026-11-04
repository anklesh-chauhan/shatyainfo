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
        Schema::create('territory_sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained()->restrictOnDelete();
            $table->string('period_type', 15);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('target_value_paise');
            $table->decimal('target_quantity', 18, 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('note');
            $table->json('revisions');
            $table->unique(['territory_id', 'period_type', 'starts_on'], 'territory_target_period_unique');
            $table->timestamps();
        });
        Schema::create('territory_sales_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->unique(['territory_id', 'effective_from'], 'territory_assignment_date_unique');
            $table->timestamps();
        });
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->foreignId('territory_id')->nullable()->constrained()->restrictOnDelete());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('territory_id'));
        Schema::dropIfExists('territory_sales_assignments');
        Schema::dropIfExists('territory_sales_targets');
    }
};

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
        Schema::create('sgip_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('account_master_id') // Customer
                ->constrained('account_masters')
                ->cascadeOnDelete();

            $table->foreignId('territory_id')->nullable()->constrained();
            $table->foreignId('sales_tour_plan_id')->nullable()->constrained();

            $table->date('visit_date');

            $table->decimal('total_value', 12, 2)->default(0);

            $table->string('approval_status')->default('draft');

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
        Schema::dropIfExists('sgip_distributions');
    }
};

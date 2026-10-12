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
        Schema::create('employment_detail_ou_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employment_detail_id')
                ->constrained('employment_details')
                ->cascadeOnDelete();

            $table->foreignId('organizational_unit_id')
                ->constrained('organizational_units')
                ->cascadeOnDelete();

            // Role & control
            $table->boolean('is_primary')->default(false);
            $table->string('role')->nullable(); // optional
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->unique(
                ['employment_detail_id', 'organizational_unit_id'],
                'emp_det_ou_unique'
            );

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
        Schema::dropIfExists('employment_detail_ou_pivot');
    }
};

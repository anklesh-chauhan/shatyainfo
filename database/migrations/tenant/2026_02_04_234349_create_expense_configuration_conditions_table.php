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
        Schema::create('expense_configuration_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expense_configuration_id')
                ->constrained()
                ->cascadeOnDelete()
                ->name('exp_cfg_cond_exp_cfg_id_fk');

            $table->string('condition_key');
            // outstation, visit_count, joint_work

            $table->string('operator');
            // =, >, <, >=

            $table->string('value');

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
        Schema::dropIfExists('expense_configuration_conditions');
    }
};

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
        Schema::create('position_organizational_unit_pivot', function (Blueprint $table) {
            $table->foreignId('position_id')->constrained('positions')->onDelete('cascade');
            $table->foreignId('organizational_unit_id')
                ->constrained('organizational_units', 'id', 'pos_org_unit_id_fk') // Short, explicit foreign key name
                ->onDelete('cascade');
            $table->primary(['position_id', 'organizational_unit_id']);
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps(); // Optional: if you want to track when the pivot was created/updated
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('position_organizational_unit_pivot');
    }
};

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
        Schema::create('territory_division_pivot', function (Blueprint $table) {
            $table->foreignId('territory_id')->constrained('territories', 'id', 'terr_org_unit_terr_id_fk')->onDelete('cascade');
            $table->foreignId('division_ou_id')->constrained('organizational_units', 'id', 'terr_org_unit_id_fk')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->primary(['territory_id', 'division_ou_id']);
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
        Schema::dropIfExists('territory_division_pivot');
    }
};

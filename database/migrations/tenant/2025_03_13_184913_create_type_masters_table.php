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
        Schema::create('type_masters', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., 'Kilogram', 'Brand A', 'Air', 'Initial Meeting'
            $table->string('description')->nullable();
            $table->nullableMorphs('typeable'); // Polymorphic relation
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
        Schema::dropIfExists('type_masters');
    }
};

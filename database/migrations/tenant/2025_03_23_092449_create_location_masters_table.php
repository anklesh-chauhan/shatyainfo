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
        Schema::create('location_masters', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., 'Kilogram', 'Brand A', 'Air', 'Initial Meeting'
            $table->string('location_code')->unique();
            $table->string('description')->nullable();
            $table->nullableMorphs('typeable'); // Polymorphic relation
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('addressable'); // Address relation
            $table->nullableMorphs('contactable'); // Contact relation
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('image')->nullable();
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
        Schema::dropIfExists('location_masters');
    }
};

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
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('modelable'); // Added Morph Relation
            $table->string('model_type')->nullable();
            $table->string('Prefix')->nullable();
            $table->integer('next_number')->default(1);
            $table->string('Suffix')->nullable();
            $table->foreignId('type_master_id')->nullable()->constrained('type_masters')->onDelete('cascade');
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
        Schema::dropIfExists('number_series');
    }
};

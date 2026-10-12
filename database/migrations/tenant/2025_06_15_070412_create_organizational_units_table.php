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
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index(); // e.g., "Sales Division"
            $table->string('code')->unique(); // e.g., "SALES-001"
            $table->foreignId('type_master_id')->constrained('type_masters')->onDelete('cascade');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('organizational_units')->nullOnDelete(); // Self-referential for hierarchy
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizational_units');
    }
};

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
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code')->unique()->nullable();
            $table->foreignId('parent_territory_id')->nullable()->constrained('territories')->onDelete('set null');
            // LINK TO DIVISION (ORGANIZATIONAL UNIT)
            $table->foreignId('region_id')->nullable()->constrained('regions')->onDelete('set null');
            $table->foreignId('division_ou_id')
                ->nullable()
                ->constrained('organizational_units')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('type_master_id')->nullable()->references('id')->on('type_masters')->onDelete('cascade');
            $table->enum('status', ['active', 'inactive'])->default('active');
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
        Schema::dropIfExists('territories');
    }
};

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
        Schema::create('job_roles', function (Blueprint $table) {
            $table->id();

            $table->string('name');          // Sales Manager
            $table->string('code')->unique(); // SM, RSM, SH
            $table->unsignedInteger('level'); // hierarchy
            $table->text('description')->nullable();

            $table->foreignId('reports_to_job_role_id')
                ->nullable()
                ->constrained('job_roles')
                ->nullOnDelete();

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
        Schema::dropIfExists('job_roles');
    }
};

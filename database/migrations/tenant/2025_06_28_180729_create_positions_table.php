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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code')->unique();

            $table->foreignId('job_role_id')
                ->constrained('job_roles');

            $table->foreignId('division_ou_id')
                ->nullable()
                ->constrained('organizational_units')
                ->restrictOnDelete();

            /**
             * Organizational Unit (Plant / Branch / Dept)
             */
            $table->foreignId('organizational_unit_id')
                ->nullable()
                ->constrained('organizational_units')
                ->nullOnDelete();

            $table->foreignId('department_id')->nullable()->constrained('emp_departments')->onDelete('restrict');
            $table->foreignId('job_title_id')->nullable()->constrained('emp_job_titles')->onDelete('restrict');
            $table->foreignId('job_grade_id')->nullable()->constrained('emp_grades')->onDelete('restrict');
            $table->foreignId('reports_to_position_id')->nullable()->constrained('positions')->onDelete('set null');
            $table->text('description')->nullable();
            $table->boolean('is_multi_territory')->default(false);
            $table->enum('status', ['active', 'inactive', 'vacant'])->default('active');
            $table->foreignId('location_id')->nullable()->constrained('location_masters')->onDelete('set null');
            $table->string('level')->nullable();
            $table->foreignId('hq_territory_id')
                ->nullable()
                ->constrained('territories')
                ->onDelete('set null');

            $table->string('approval_status')->default('pending');

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
        Schema::dropIfExists('positions');
    }
};

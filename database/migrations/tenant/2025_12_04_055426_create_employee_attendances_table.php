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
        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->onDelete('cascade');
            $table->date('attendance_date');
            $table->time('check_in')
                ->nullable();
            $table->time('check_out')
                ->nullable();
            $table->decimal('total_hours', 5, 2)
                ->nullable();
            $table->foreignId('status_id')
                ->constrained('employee_attendance_statuses')
                ->onDelete('cascade')
                ->nullable();
            $table->string('check_in_ip')
                ->nullable();
            $table->string('check_out_ip')
                ->nullable();
            $table->string('check_in_latitude')
                ->nullable();
            $table->string('check_in_longitude')
                ->nullable();
            $table->string('check_out_latitude')
                ->nullable();
            $table->string('check_out_longitude')
                ->nullable();
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
        Schema::dropIfExists('employee_attendances');
    }
};

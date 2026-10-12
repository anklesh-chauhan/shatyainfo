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
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Punch datetime (machine timestamp)
            $table->date('punch_date');
            $table->time('punch_time');

            // IN / OUT
            $table->enum('punch_type', ['in', 'out']);

            // Source details
            $table->string('source')->nullable(); // biometric, mobile, manual
            $table->string('device_id')->nullable();
            $table->string('location')->nullable();

            // Optional raw payload from machine
            $table->json('raw_payload')->nullable();

            // Indexes
            $table->index(['employee_id', 'punch_date']);

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
        Schema::dropIfExists('attendance_punches');
    }
};

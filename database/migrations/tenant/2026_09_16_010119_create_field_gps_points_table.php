<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_gps_points', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->string('source')->default('field-api');
            $table->string('device_id')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->json('raw_payload')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'recorded_at']);
            $table->index(['visit_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_gps_points');
    }
};

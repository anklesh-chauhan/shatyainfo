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
        Schema::create('leave_notification_logs', function (Blueprint $table) {
            $table->id();

            $table->string('event');
            $table->foreignId('leave_application_id')->nullable();
            $table->string('channel'); // email / sms
            $table->string('recipient_type'); // employee / manager / substitute
            $table->foreignId('recipient_id')->nullable();

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
        Schema::dropIfExists('leave_notification_logs');
    }
};

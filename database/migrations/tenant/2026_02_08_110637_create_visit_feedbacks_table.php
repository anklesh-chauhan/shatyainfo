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
        Schema::create('visit_feedbacks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('visit_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('visit_feedback_question_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('answer')->nullable();
            $table->text('remarks')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->unique(['visit_id', 'visit_feedback_question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_feedbacks');
    }
};

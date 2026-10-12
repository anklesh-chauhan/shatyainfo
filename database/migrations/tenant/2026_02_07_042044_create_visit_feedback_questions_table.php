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
        Schema::create('visit_feedback_questions', function (Blueprint $table) {
            $table->id();

            $table->string('question');
            $table->string('code')->unique(); // ATTENTION, INTEREST, RESPONSE
            $table->enum('answer_type', [
                'rating_1_5',
                'rating_1_10',
                'yes_no',
                'scale_low_medium_high',
            ]);

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

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
        Schema::dropIfExists('visit_feedback_questions');
    }
};

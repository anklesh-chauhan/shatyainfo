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
        Schema::create('visit_document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->morphs('documentable');
            // documentable_type
            // documentable_id

            $table->unique([
                'visit_id',
                'documentable_type',
                'documentable_id',
            ], 'visit_document_link_unique');
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
        Schema::dropIfExists('visit_document_links');
    }
};

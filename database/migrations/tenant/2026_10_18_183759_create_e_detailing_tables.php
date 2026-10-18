<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_detailing_materials', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('item_master_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('content_version', 64);
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('e_detailing_presentations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('e_detailing_material_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('material_title');
            $table->string('content_version', 64);
            $table->text('notes')->nullable();
            $table->timestamp('presented_at');
            $table->timestamps();
            $table->unique(['visit_id', 'e_detailing_material_id', 'content_version'], 'e_detailing_visit_material_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_detailing_presentations');
        Schema::dropIfExists('e_detailing_materials');
    }
};

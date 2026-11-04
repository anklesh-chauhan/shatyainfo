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
        Schema::table('account_masters', function (Blueprint $table) {
            $table->decimal('geo_latitude', 10, 7)->nullable();
            $table->decimal('geo_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geo_radius_meters')->default(100);
        });
        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->boolean('enforce_geo_fencing')->default(false);
            $table->foreignId('geo_asm_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('visit_geo_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->string('stage', 20);
            $table->unique(['visit_id', 'stage']);
            $table->string('result', 30);
            $table->decimal('distance_meters', 12, 2)->nullable();
            $table->unsignedSmallInteger('radius_meters');
            $table->json('evidence');
            $table->boolean('low_accuracy')->default(false);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_geo_assessments');
        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('geo_asm_user_id');
            $table->dropColumn('enforce_geo_fencing');
        });
        Schema::table('account_masters', fn (Blueprint $table) => $table->dropColumn(['geo_latitude', 'geo_longitude', 'geo_radius_meters']));
    }
};

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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->date('founded_at')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_email')->nullable();
            $table->string('contact_person_phone')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('gst_number')->nullable();
            $table->date('registration_date')->nullable();
            $table->string('legal_status')->nullable();
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->string('size')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->string('operation_hours')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreignId('industry_type_id')->nullable()->constrained('industry_types')->onDelete('set null');
            $table->foreign('parent_organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->blameable();
            $table->blameableSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};

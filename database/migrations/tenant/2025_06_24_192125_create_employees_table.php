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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 20)->unique();
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
            $table->string('email', 100)->unique()->nullable();
            $table->string('personal_email', 100)->unique()->nullable();
            $table->string('mobile_number', 100)->unique();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->unsignedBigInteger('country_id')->nullable()->comment('Nationality');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
            $table->enum('marital_status', ['Single', 'Married', 'Divorced'])->nullable();
            $table->string('phone_number', 15)->nullable();
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_number', 15)->nullable();
            $table->unsignedInteger('age')->nullable();
            $table->text('contact_details')->nullable();
            $table->string('profile_picture')->nullable();
            $table->enum('blood_group', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('login_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('approval_status')->default('draft');
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
        Schema::dropIfExists('employees');
    }
};

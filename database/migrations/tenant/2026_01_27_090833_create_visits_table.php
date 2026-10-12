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
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            $table->string('document_number')->unique();

            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('reporting_manager_id')->nullable()->constrained('users');

            $table->foreignId('territory_id')->constrained();
            $table->foreignId('patch_id')->constrained();

            $table->foreignId('sales_tour_plan_id')->nullable()->constrained('sales_tour_plans');

            $table->foreignId('sales_tour_plan_detail_id')->nullable()->constrained('sales_tour_plan_details');

            $table->date('visit_date');
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();

            $table->enum('visit_type', ['planned', 'unplanned'])->default('planned');

            $table->enum('visit_status', [
                'draft',
                'started',
                'completed',
                'cancelled',
                'checked_out_pending',
            ])->default('draft');

            $table->enum('reschedule_state', [
                'none',
                'requested',
                'approved',
                'auto_rescheduled',
            ])->default('none');

            $table->date('rescheduled_for')->nullable();

            $table->foreignId('rescheduled_visit_id')
                ->nullable()
                ->constrained('visits')
                ->nullOnDelete();

            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();

            $table->text('remarks')->nullable();
            $table->json('attachments')->nullable();

            $table->foreignId('visit_outcome_id')->nullable()->constrained('visit_outcomes');

            $table->decimal('checkin_latitude', 10, 7)->nullable();
            $table->decimal('checkin_longitude', 10, 7)->nullable();

            $table->decimal('checkout_latitude', 10, 7)->nullable();
            $table->decimal('checkout_longitude', 10, 7)->nullable();

            $table->decimal('image_latitude', 10, 7)->nullable();
            $table->decimal('image_longitude', 10, 7)->nullable();

            $table->boolean('is_joint_work')->default(false);
            $table->foreignId('visit_purpose_id')->nullable()->constrained('visit_purposes')->onDelete('set null');

            $table->text('cancel_reason')->nullable();

            $table->string('_status')->default('draft');
            $table->timestamp('draft_saved_at')->nullable();

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
        Schema::dropIfExists('visits');
    }
};

<?php

namespace App\Traits;

use App\Models\AccountMaster;
use App\Models\NumberSeries;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use App\Models\VisitFeedbackQuestion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

trait HasVisitManagement
{
    /**
     * Finds or creates a Visit and initializes feedback questions.
     */
    protected function ensureVisitExists(
        int $detailId,
        int $patchId,
        int $companyId,
        ?int $visitId = null
    ): Visit {
        $user = Auth::user();
        abort_unless($user?->can(['ViewAny:Visit', 'View:Visit']), 403);

        $detail = SalesTourPlanDetail::query()
            ->whereHas('tourPlan', fn ($plan) => $plan->where('user_id', $user->id))
            ->findOrFail($detailId);
        $patch = $detail->patches()->whereKey($patchId)
            ->whereHas('companies', fn ($accounts) => $accounts->whereKey($companyId))
            ->firstOrFail();

        $visits = Visit::query()
            ->where('employee_id', $user->id)
            ->where('sales_tour_plan_detail_id', $detailId)
            ->where('patch_id', $patchId)
            ->whereHas('visitables', fn ($participants) => $participants
                ->where('visitable_type', AccountMaster::class)
                ->where('visitable_id', $companyId));

        if ($visitId !== null) {
            return $visits->findOrFail($visitId);
        }

        $existingVisit = $visits->first();
        if ($existingVisit) {
            return $existingVisit;
        }

        Gate::authorize('create', Visit::class);

        return $detail->getConnection()->transaction(function () use ($detail, $patch, $companyId): Visit {
            $docNum = NumberSeries::getNextNumber(Visit::class);

            $visit = Visit::create([
                'document_number' => $docNum,
                'employee_id' => Auth::id(),
                'reporting_manager_id' => Auth::user()->reporting_manager_id,
                'sales_tour_plan_id' => $detail->sales_tour_plan_id,
                'sales_tour_plan_detail_id' => $detail->id,
                'territory_id' => $patch->territory_id,
                'patch_id' => $patch->id,
                'visit_date' => today(),
                'visit_type' => 'planned',
                'visit_status' => 'draft',
                'approval_status' => 'pending',
                'remarks' => $detail->remarks,
                'is_joint_work' => ! empty($detail->joint_with),
            ]);

            // Increemnt Number Series
            NumberSeries::incrementNextNumber(Visit::class);

            // Attach Polymorphic Company
            $visit->visitables()->create([
                'visitable_type' => AccountMaster::class,
                'visitable_id' => $companyId,
            ]);

            if (! empty($detail->joint_with)) {
                $visit->jointUsers()->sync($detail->joint_with);
            }

            if (! empty($detail->visit_purpose_ids)) {
                $visit->visitPurposes()->sync($detail->visit_purpose_ids);
            }

            // Initialize Feedback Questions
            $this->initializeVisitFeedbacks($visit);

            return $visit->fresh();
        });
    }

    /**
     * Seed feedback questions for a visit (idempotent).
     */
    protected function initializeVisitFeedbacks(Visit $visit): void
    {
        $questions = VisitFeedbackQuestion::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($questions as $question) {
            $visit->feedbacks()->firstOrCreate(
                ['visit_feedback_question_id' => $question->id],
                [
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Expected $feedbackData format:
     * [
     *   question_id => [
     *       'answer'  => 3,
     *       'remarks' => 'Customer was interested',
     *   ],
     * ]
     */
    public function updateVisitFeedbacks(Visit $visit, array $feedbackData): void
    {
        foreach ($feedbackData as $row) {

            // Each $row is one repeater item
            if (
                ! isset($row['visit_feedback_question_id']) ||
                ! is_numeric($row['visit_feedback_question_id'])
            ) {
                continue;
            }

            $questionId = (int) $row['visit_feedback_question_id'];

            $visit->feedbacks()
                ->where('visit_feedback_question_id', $questionId)
                ->update([
                    'answer' => $row['answer'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                    'updated_by' => Auth::id(),
                ]);
        }
    }
}

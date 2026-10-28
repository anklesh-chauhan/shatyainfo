<?php

namespace App\Services\Field;

use App\Helpers\SalesDocumentQuickCreate;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedbackQuestion;
use App\Models\VisitOutcome;
use App\Models\VisitPreference;
use App\Models\VisitPurpose;
use App\Services\JointUserService;
use App\Services\Visit\VisitEnforcementService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FieldVisitWorkspaceService
{
    public function options(User $user): array
    {
        $prefs = VisitPreference::current();

        return [
            'outcomes' => VisitOutcome::query()->orderBy('label')->get(['id', 'label']),
            'purposes' => VisitPurpose::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'joint_users' => JointUserService::getJointUsersForUser($user)->orderBy('name')->get(['id', 'name']),
            'feedback_questions' => VisitFeedbackQuestion::active()->get(['id', 'question']),
            'field_rules' => $prefs->field_rules ?? [],
            'allow_manual_time_edit' => (bool) $prefs->allow_manual_time_edit,
        ];
    }

    public function load(Visit $visit): Visit
    {
        return $visit->load([
            'outcome', 'patch', 'visitPurposes', 'jointUsers', 'feedbacks.question',
            'followUps', 'salesDcr.expenses.expenseType', 'salesDcr.expenses.transportMode',
            'visitDocumentLinks.documentable',
        ]);
    }

    public function save(User $user, Visit $visit, array $data): Visit
    {
        return $user->getConnection()->transaction(function () use ($user, $visit, $data): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            abort_unless((int) $visit->employee_id === (int) $user->id, 404, 'Visit not found.');
            abort_if($visit->isCancelled(), 422, 'This visit can no longer be edited.');
            $prefs = VisitPreference::current();
            $fields = [
                'visit_outcome_id' => 'outcome',
                'remarks' => 'remarks',
                'visit_purpose_ids' => 'purpose',
                'next_follow_up_date' => 'next_follow_up_date',
            ];
            foreach ($fields as $key => $rule) {
                abort_if(array_key_exists($key, $data) && ! $prefs->isFieldVisible($rule), 422, 'This field is not available.');
            }
            if (array_key_exists('visit_outcome_id', $data) && $data['visit_outcome_id'] !== null) {
                abort_unless(VisitOutcome::query()->whereKey($data['visit_outcome_id'])->exists(), 422, 'Select a valid outcome.');
            }
            if (array_key_exists('visit_purpose_ids', $data)) {
                $valid = VisitPurpose::query()->where('is_active', true)->whereIn('id', $data['visit_purpose_ids'])->count();
                abort_unless($valid === count(array_unique($data['visit_purpose_ids'])), 422, 'Select active visit purposes.');
            }
            if (array_key_exists('joint_user_ids', $data)) {
                $allowed = JointUserService::getJointUsersForUser($user)->pluck('id')->all();
                abort_if(array_diff($data['joint_user_ids'], $allowed), 422, 'Select eligible joint employees.');
            }

            $visit->fill(collect($data)->only(['visit_outcome_id', 'remarks', 'is_joint_work'])->all());
            if (! empty($data['start_time']) || ! empty($data['end_time'])) {
                abort_unless($prefs->allow_manual_time_edit, 422, 'Manual time editing is disabled.');
                $day = $visit->visit_date?->toDateString() ?? today()->toDateString();
                foreach (['start_time', 'end_time'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $visit->{$field} = $data[$field] === null ? null : Carbon::parse($day.' '.$data[$field]);
                    }
                }
            }
            foreach ($fields as $key => $rule) {
                if (! $prefs->isFieldRequired($rule)) {
                    continue;
                }
                $value = match ($key) {
                    'next_follow_up_date' => array_key_exists($key, $data)
                        ? $data[$key] : $visit->followUps()->latest('id')->value('next_follow_up_date'),
                    'visit_purpose_ids' => array_key_exists($key, $data)
                        ? $data[$key] : $visit->visitPurposes()->pluck('visit_purposes.id')->all(),
                    default => array_key_exists($key, $data) ? $data[$key] : $visit->{$key},
                };
                if (blank($value)) {
                    throw ValidationException::withMessages([$key => 'This field is required.']);
                }
            }
            app(VisitEnforcementService::class)->validateBeforeSave($visit);
            $visit->save();
            if (array_key_exists('visit_purpose_ids', $data)) {
                $visit->visitPurposes()->sync($data['visit_purpose_ids']);
            }
            if (array_key_exists('joint_user_ids', $data)) {
                $visit->jointUsers()->sync($data['joint_user_ids']);
            } elseif (array_key_exists('is_joint_work', $data) && ! $data['is_joint_work']) {
                $visit->jointUsers()->detach();
            }
            foreach ($data['feedback'] ?? [] as $answer) {
                abort_unless(VisitFeedbackQuestion::query()->whereKey($answer['question_id'])->exists(), 422, 'Select a valid feedback question.');
                $visit->feedbacks()->updateOrCreate(
                    ['visit_feedback_question_id' => $answer['question_id']],
                    ['answer' => $answer['answer']],
                );
            }
            if (array_key_exists('next_follow_up_date', $data)) {
                $previous = $visit->followUps()->latest('id')->value('next_follow_up_date');
                $previous = $previous ? substr((string) $previous, 0, 10) : null;
                if ($data['next_follow_up_date'] !== $previous) {
                    $visit->followUps()->create([
                        'user_id' => $user->id,
                        'follow_up_date' => now(),
                        'next_follow_up_date' => $data['next_follow_up_date'],
                    ]);
                }
            }

            return $this->load($visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable', 'sgipDistribution.items']));
        });
    }

    public function createDocument(User $user, Visit $visit, string $type): array
    {
        abort_unless((int) $visit->employee_id === (int) $user->id, 404, 'Visit not found.');
        $company = $visit->primaryCompany();
        abort_unless($company, 422, 'Link a customer before creating a sales document.');
        abort_unless($company->contactDetails()->exists(), 422, 'Add a customer contact before creating a sales document.');
        $class = $type === 'quote' ? Quote::class : SalesOrder::class;
        $document = $user->getConnection()->transaction(
            fn () => SalesDocumentQuickCreate::createFromVisit($visit, $class),
        );

        return [
            'id' => $document->id,
            'type' => $type,
            'document_number' => $document->document_number,
            'status' => $document->status,
            'total' => (float) $document->total,
            'web_path' => '/admin/'.($type === 'quote' ? 'quotes' : 'sales-orders').'/'.$document->id.'/edit',
        ];
    }
}

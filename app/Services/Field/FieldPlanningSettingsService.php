<?php

namespace App\Services\Field;

use App\Enums\FieldPlanningMode;
use App\Models\FieldPlanningActivation;
use App\Models\FieldPlanningRevision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FieldPlanningSettingsService
{
    public const PERMISSION = 'Manage:FieldPlanningPreference';

    /** @return array<string, string> */
    public static function modeOptions(): array
    {
        return [
            'direct_dcr' => 'Direct DCR',
            'flexible' => 'Flexible planning',
            'formal_mtp' => 'Formal MTP',
        ];
    }

    /** @return array<string, string> */
    public static function ruleLabels(): array
    {
        return [
            'require_plan' => 'Require a plan for normal calls',
            'require_approved_plan' => 'Require an approved plan',
            'require_territory' => 'Require territory',
            'require_patch' => 'Require patch',
            'enable_flexible_plans' => 'Enable daily or weekly plans',
        ];
    }

    public static function canManage(?User $user): bool
    {
        return (bool) ($user && ($user->can(self::PERMISSION)
            || $user->hasAnyRole(['super_admin', 'administration_admin'])));
    }

    public function latest(User $actor): ?FieldPlanningRevision
    {
        abort_unless(self::canManage($actor), 403);

        return FieldPlanningRevision::query()->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    public function initialProposal(): array
    {
        return [
            'mode' => FieldPlanningMode::DirectDcr->value,
            'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr),
            'proposed_effective_on' => null,
        ];
    }

    /**
     * The unique version makes competing saves fail instead of overwriting history.
     * Only explicitly activated revisions are used for runtime enforcement.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveProposal(User $actor, array $input, int $expectedVersion): FieldPlanningRevision
    {
        abort_unless(self::canManage($actor), 403);
        $validator = Validator::make($input, [
            'mode' => ['required', Rule::in(array_keys(self::modeOptions()))],
            'rules' => ['required', 'array:require_plan,require_approved_plan,require_territory,require_patch,unplanned_calls,enable_flexible_plans'],
            'rules.require_plan' => ['required', 'boolean'],
            'rules.require_approved_plan' => ['required', 'boolean'],
            'rules.require_territory' => ['required', 'boolean'],
            'rules.require_patch' => ['required', 'boolean'],
            'rules.enable_flexible_plans' => ['required', 'boolean'],
            'rules.unplanned_calls' => ['required', Rule::in(['allow', 'allow_with_reason', 'block'])],
            'proposed_effective_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tomorrow'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $validator->after(function ($validator) use ($input): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $rules = $input['rules'];
            if ($rules['require_approved_plan'] && ! $rules['require_plan']) {
                $validator->errors()->add('rules.require_approved_plan', 'An approved plan can only be required when a plan is required.');
            }
            if ($rules['require_patch'] && ! $rules['require_territory']) {
                $validator->errors()->add('rules.require_patch', 'A required patch must have a required territory.');
            }
            if ($input['mode'] !== FieldPlanningMode::FormalMtp->value && $rules['require_plan']) {
                $validator->errors()->add('rules.require_plan', 'Direct DCR and flexible planning do not require a prior plan.');
            }
            if ($input['mode'] === FieldPlanningMode::FormalMtp->value && ! $rules['require_plan']) {
                $validator->errors()->add('rules.require_plan', 'Formal MTP requires a plan for normal calls.');
            }
            if ($rules['enable_flexible_plans'] && $input['mode'] !== FieldPlanningMode::Flexible->value) {
                $validator->errors()->add('rules.enable_flexible_plans', 'Select flexible planning to enable daily or weekly plans.');
            }
            if (trim($input['reason']) === '') {
                $validator->errors()->add('reason', 'Explain why these settings are proposed.');
            }
        });
        $data = $validator->validate();
        foreach (array_keys(self::ruleLabels()) as $key) {
            $data['rules'][$key] = (bool) $data['rules'][$key];
        }
        $data['reason'] = trim($data['reason']);

        try {
            return (new FieldPlanningRevision)->getConnection()->transaction(function () use ($actor, $data, $expectedVersion): FieldPlanningRevision {
                $latest = $this->latest($actor);
                if (($latest?->version ?? 0) !== $expectedVersion) {
                    throw $this->staleProposal();
                }

                return FieldPlanningRevision::query()->create([
                    ...$data,
                    'version' => $expectedVersion + 1,
                    'previous_revision_id' => $latest?->id,
                    'created_by' => $actor->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->staleProposal();
        }
    }

    public function activate(User $actor, int $revisionId, int $expectedVersion): void
    {
        abort_unless(self::canManage($actor), 403);
        $actor->getConnection()->transaction(function () use ($actor, $revisionId, $expectedVersion): void {
            $actor->newQuery()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $revision = $this->latest($actor);
            if (! $revision || $revision->id !== $revisionId || $revision->version !== $expectedVersion) {
                throw $this->staleProposal();
            }
            FieldPlanningActivation::query()->create([
                'field_planning_revision_id' => $revision->id,
                'created_by' => $actor->id,
                'effective_on' => $revision->proposed_effective_on?->isFuture() ? $revision->proposed_effective_on : today(),
            ]);
        });
    }

    private function staleProposal(): ValidationException
    {
        return ValidationException::withMessages([
            'mode' => 'Another administrator saved a newer proposal. Reload the page and review it before saving again.',
        ]);
    }
}

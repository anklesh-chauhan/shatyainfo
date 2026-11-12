<?php

namespace App\Services\Field;

use App\Enums\FieldPlanningMode;
use App\Models\FieldPlanningActivation;
use App\Models\FieldPlanningRevision;

class FieldPlanningService
{
    /**
     * Deployment capabilities only, never a replacement for user authorization.
     * A tenant uses its current workflow until an authorized policy is activated.
     *
     * @return array<string, mixed>
     */
    public function active(): ?FieldPlanningRevision
    {
        return FieldPlanningActivation::query()->with('revision')->whereDate('effective_on', '<=', today())->orderByDesc('effective_on')->orderByDesc('id')->first()?->revision;
    }

    public function capabilities(): array
    {
        $active = $this->active();

        return [
            'policy_revision' => $active?->id,
            'schema_version' => 1,
            'mode' => $active?->mode->value ?? FieldPlanningMode::Legacy->value,
            'supports_direct_calls' => in_array($active?->mode, [FieldPlanningMode::DirectDcr, FieldPlanningMode::Flexible], true),
            'supports_flexible_plans' => $active?->mode === FieldPlanningMode::Flexible && ($active->rules['enable_flexible_plans'] ?? false),
            'supports_mtp' => in_array($active?->mode, [null, FieldPlanningMode::Legacy, FieldPlanningMode::FormalMtp], true),
            'allows_unplanned_calls' => ($active?->rules['unplanned_calls'] ?? 'allow') !== 'block',
            'requires_unplanned_reason' => ($active?->rules['unplanned_calls'] ?? 'allow') === 'allow_with_reason',
        ];
    }

    /**
     * Preset values for a saved proposal; activation is a separate operation.
     * Legacy is intentionally absent: its existing behavior must be inventoried,
     * not replaced by guessed defaults.
     *
     * @return array<string, bool|string>|null
     */
    public function preset(FieldPlanningMode $mode): ?array
    {
        return match ($mode) {
            FieldPlanningMode::Legacy => null,
            FieldPlanningMode::DirectDcr => [
                'require_plan' => false,
                'require_approved_plan' => false,
                'require_territory' => false,
                'require_patch' => false,
                'unplanned_calls' => 'allow',
                'enable_flexible_plans' => false,
            ],
            FieldPlanningMode::Flexible => [
                'require_plan' => false,
                'require_approved_plan' => false,
                'require_territory' => false,
                'require_patch' => false,
                'unplanned_calls' => 'allow_with_reason',
                'enable_flexible_plans' => true,
            ],
            FieldPlanningMode::FormalMtp => [
                'require_plan' => true,
                'require_approved_plan' => true,
                'require_territory' => true,
                'require_patch' => true,
                'unplanned_calls' => 'block',
                'enable_flexible_plans' => false,
            ],
        };
    }
}

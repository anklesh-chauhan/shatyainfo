<?php

namespace App\Traits;

use App\Models\ApprovalSetting;
use App\Models\HrChangeRequest;
use App\Services\HR\HrChangeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait RequiresHrChangeApproval
{
    protected static function bootRequiresHrChangeApproval(): void
    {
        static::updating(function (Model $model): void {
            if (HrChangeService::isAuthorizedUpdate() || ! ApprovalSetting::moduleRequiresApproval(HrChangeRequest::class)) {
                return;
            }
            $fields = [];
            foreach (array_keys(HrChangeService::types()) as $type) {
                if (HrChangeService::modelClass($type) === $model::class) {
                    $fields = [...$fields, ...array_keys(HrChangeService::rules($type))];
                }
            }
            if ($model->isDirty($fields)) {
                throw ValidationException::withMessages(['approval' => 'Submit this change through HR Change Requests. Current values remain active until approved.']);
            }
        });
    }
}

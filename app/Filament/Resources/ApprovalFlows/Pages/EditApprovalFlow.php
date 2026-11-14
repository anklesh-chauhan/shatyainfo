<?php

namespace App\Filament\Resources\ApprovalFlows\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditApprovalFlow extends EditRecord
{
    protected static string $resource = ApprovalFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $flow = $this->getRecord();
        $data['name'] = $flow->display_name;
        $data['modules'] = $flow->modules;
        $data['levels'] = $flow->steps->map(function ($step): array {
            $level = $step->toArray();
            $level['approver_employee_id'] ??= $step->approver?->employee?->id;

            return $level;
        })->all();
        $data['allow_optional_role_levels'] = $flow->steps->contains('can_skip', true);
        $data['deadline_hours'] = $flow->steps->first()?->sla_hours ?? 24;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record->saveConfiguration($data);
    }
}

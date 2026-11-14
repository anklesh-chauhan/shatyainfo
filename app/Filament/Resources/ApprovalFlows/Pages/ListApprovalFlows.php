<?php

namespace App\Filament\Resources\ApprovalFlows\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\ApprovalFlows\Schemas\ApprovalFlowForm;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\Territory;
use App\Services\Approval\ApprovalService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use LogicException;

class ListApprovalFlows extends ListRecords
{
    protected static string $resource = ApprovalFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('previewRoute')->label('Preview approval route')
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => ApprovalFlowForm::employeeOptions())->required()->searchable(),
                    Select::make('module')->options(fn () => ApprovalSetting::approvedModuleOptions())->required()->searchable(),
                    Select::make('territory_id')->label('Territory')->options(fn () => Territory::query()->orderBy('name')->pluck('name', 'id'))->searchable()->helperText('Provide a territory for a territory-restricted flow.'),
                    TextInput::make('amount')->numeric()->helperText('Required when the flow has amount limits.'),
                    DatePicker::make('date')->helperText('Leave blank to preview the current route.'),
                ])
                ->action(function (array $data): void {
                    Gate::authorize('viewAny', ApprovalFlow::class);
                    $employee = Employee::query()->findOrFail($data['employee_id']);
                    try {
                        $route = app(ApprovalService::class)->resolveRoute(
                            $employee, $data['module'], filled($data['territory_id'] ?? null) ? (int) $data['territory_id'] : null,
                            filled($data['amount'] ?? null) ? (float) $data['amount'] : null, $data['date'] ?? null, $employee->login_id,
                        );
                    } catch (LogicException $exception) {
                        Notification::make()->danger()->title('Approval route could not be resolved')->body($exception->getMessage())->persistent()->send();

                        return;
                    }
                    Gate::authorize('view', $route['flow']);
                    $lines = array_map(function (array $level): string {
                        $approver = $level['assigned'];
                        $original = $level['original'];
                        $name = $approver?->name ?? 'Skipped';
                        if ($approver && ! $approver->is($original)) {
                            $name .= ' (delegated by '.$original->name.')';
                        }

                        return $level['step']->step_order.'. '.$name.' — '.$level['reason'];
                    }, $route['steps']);
                    Notification::make()->title($route['flow']->display_name)
                        ->body(implode('<br>', array_map('e', $lines)))
                        ->persistent()->send();
                }),
        ];
    }
}

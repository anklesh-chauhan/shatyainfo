<?php

namespace App\Filament\Resources\ApprovalFlows\Tables;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApprovalFlowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['steps.jobRole', 'steps.selectedEmployee.user'])->withCount('steps'))
            ->columns([
                TextColumn::make('display_name')->label('Flow name')->searchable(['name', 'module']),
                TextColumn::make('modules')->state(fn (ApprovalFlow $record): array => array_map(fn (string $module): string => str($module)->headline()->toString(), $record->modules))->badge(),
                TextColumn::make('route_summary')->label('Approval route'),
                TextColumn::make('steps_count')->label('Levels'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('setup_issues')->state(fn (ApprovalFlow $record): string => match (true) {
                    $record->steps_count === 0 => 'Add an approval level',
                    $record->steps->first()?->routing_type === 'previous_manager' => 'Choose a different approver type for level 1',
                    $record->steps->contains(fn ($step): bool => $step->routing_type === 'job_role' && ! $step->jobRole) => 'Review the job role on each level',
                    $record->steps->contains(fn ($step): bool => ($step->routing_type === 'specific_employee' || $step->approver_employee_id)
                        && (! $step->selectedEmployee?->is_active || ! $step->selectedEmployee?->user)) => 'Review selected employees and their logins',
                    array_diff($record->modules, array_keys(ApprovalSetting::approvedModuleOptions())) !== [] => 'Some modules have approvals disabled',
                    default => '',
                }),
            ])
            ->filters([
                SelectFilter::make('module')->options(fn (): array => ApprovalSetting::approvedModuleOptions())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->forModule($data['value']) : $query),
                TernaryFilter::make('active')->label('Status'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')->label('Duplicate')
                    ->visible(fn (ApprovalFlow $record): bool => Gate::allows('create', ApprovalFlow::class) && Gate::allows('replicate', $record))
                    ->action(function (ApprovalFlow $record): void {
                        Gate::authorize('create', ApprovalFlow::class);
                        Gate::authorize('replicate', $record);
                        $copy = DB::transaction(function () use ($record): ApprovalFlow {
                            $copy = new ApprovalFlow($record->only($record->getFillable()));
                            $copy->fill(['name' => $record->display_name.' (copy)', 'active' => false, 'version' => 1])->save();
                            DB::table('approval_flow_modules')->insert(array_map(fn (string $module): array => ['approval_flow_id' => $copy->id, 'module' => $module], $record->modules));
                            foreach ($record->steps as $step) {
                                $copy->steps()->create($step->getAttributes());
                            }

                            return $copy;
                        });
                        Notification::make()->success()->title('Inactive copy created')->send();
                        redirect(ApprovalFlowResource::getUrl('edit', ['record' => $copy]));
                    }),
            ])
            ->emptyStateHeading('No approval flows')
            ->emptyStateDescription('Enable approval modules in Approval Settings, then create a reporting manager flow.');
    }
}

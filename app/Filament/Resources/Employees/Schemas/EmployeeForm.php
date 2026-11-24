<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\HrChangeRequest;
use App\Services\HR\EmployeeRegister;
use App\Services\HR\EmployeeRegisterFields;
use App\Services\HR\HrChangeService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

class EmployeeForm
{
    public static function fields(string $section, ?Employee $owner = null, bool $change = false): array
    {
        $components = $change ? [] : [Hidden::make($section === 'employee' ? 'revision' : 'employee_revision')
            ->default(fn (): ?string => $owner?->exists ? app(EmployeeRegister::class)->revision($owner->fresh()) : null)];
        foreach (EmployeeRegisterFields::fields($section) as $key => $field) {
            if ($change && ! array_key_exists($key, HrChangeService::rules($section))) {
                continue;
            }
            $component = match ($field['type']) {
                'bool' => Toggle::make($key)->default($key === 'is_active' || $key === 'is_current'),
                'date' => DatePicker::make($key),
                'textarea' => Textarea::make($key),
                'select' => Select::make($key)->options($field['options']),
                'picker', 'multi' => Select::make($key)->searchable()->multiple($field['type'] === 'multi')
                    ->options(function (Get $get) use ($field, $key, $owner): array {
                        $rows = app(EmployeeRegister::class)->catalog(auth()->user(), $field['kind'], $owner, $get('division_ou_id') ? (int) $get('division_ou_id') : null)->get();
                        $column = EmployeeRegister::OPTIONS[$field['kind']][1];
                        $options = $rows->mapWithKeys(fn (Model $row): array => [$row->id => $field['kind'] === 'managers' ? $row->full_name.' ('.$row->employee_id.')' : $row->{$column}])->all();
                        foreach (array_filter((array) $get($key)) as $id) {
                            $options[$id] ??= 'Existing linked selection';
                        }

                        return $options;
                    }),
                default => TextInput::make($key),
            };
            $component->label($field['label'])->required($field['required']);
            if (array_key_exists('default', $field)) {
                $component->default($field['default']);
            }
            if (isset($field['max_length'])) {
                $component->maxLength($field['max_length']);
            }
            if (in_array($field['type'], ['money', 'percent', 'year'], true)) {
                $component->numeric();
            }
            if ($field['type'] === 'email') {
                $component->email();
            }
            if ($key === 'grade_id') {
                $component->createOptionForm([TextInput::make('grade_name')->required()->maxLength(50)])
                    ->createOptionUsing(fn (array $data): int => app(EmployeeRegister::class)->createGrade(auth()->user(), $data['grade_name'])->id)
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can('Create:EmpGrade')));
            }
            if ($key === 'division_ou_id') {
                $component->live()->afterStateUpdated(fn (callable $set) => $set('org_unit_ids', []));
            }
            if ($key === 'provision_login') {
                $component->default(false)->visible(fn (): bool => auth()->user()->can('Create:User'))->dehydratedWhenHidden()->dehydrated(true);
            }
            if (! $change) {
                $component->disabled(fn (?Model $record): bool => $record?->exists && ApprovalSetting::moduleRequiresApproval(HrChangeRequest::class)
                    && in_array($section, ['employee', 'employment'], true) && array_key_exists($key, HrChangeService::rules($section)))->dehydrated(true);
            }
            $components[] = $component;
        }
        if ($section === 'employee' && ! $change) {
            $components[] = TextInput::make('resolved_reporting_manager')->label('Reporting manager')->disabled()->dehydrated(false);

            $components[] = FileUpload::make('profile_picture')->image()->avatar()->imageEditor()->imageCropAspectRatio('1:1')->imageResizeMode('cover')->imageResizeTargetWidth(512)->imageResizeTargetHeight(512)->maxSize(1024)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->storeFiles(false);
        }

        return $components;
    }
}

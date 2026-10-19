<?php

namespace App\Filament\Resources\HrChangeRequests;

use App\Filament\Resources\HrChangeRequests\Pages\ManageHrChangeRequests;
use App\Models\Employee;
use App\Models\HrChangeRequest;
use App\Services\HR\HrChangeService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HrChangeRequestResource extends Resource
{
    protected static ?string $model = HrChangeRequest::class;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'HR Change Requests';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(! auth()->user()->can('Update:EmpSalary'), fn ($query) => $query->where('request_type', '!=', 'salary'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('request_type')->options(HrChangeService::types())->required()->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('target_id', null);
                    $set('proposed_values', []);
                }),
            Select::make('employee_id')->label('Employee')->options(fn (): array => Employee::query()->get()->filter(fn ($employee): bool => auth()->user()->can('view', $employee))->pluck('full_name', 'id')->all())->searchable()->required()->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('target_id', null);
                    $set('proposed_values', []);
                }),
            Select::make('target_id')->label('Record to change')->required()->live()
                ->options(function (Get $get): array {
                    if (! $get('request_type') || ! $get('employee_id')) {
                        return [];
                    }
                    $class = HrChangeService::modelClass($get('request_type'));

                    return $class::query()->where($class === Employee::class ? 'id' : 'employee_id', $get('employee_id'))->get()
                        ->filter(fn ($record): bool => auth()->user()->can('update', $record))
                        ->mapWithKeys(fn ($record): array => [$record->id => '#'.$record->id.' '.($record->attendance_date?->format('Y-m-d') ?? $record->full_name ?? '')])->all();
                })
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    if (! $get('target_id')) {
                        return;
                    }
                    $class = HrChangeService::modelClass($get('request_type'));
                    $record = $class::find($get('target_id'));
                    if ($record && auth()->user()->can('update', $record)) {
                        $values = $record->only(array_keys(HrChangeService::rules($get('request_type'))));
                        if ($get('request_type') === 'overtime') {
                            $values['ot_minutes'] = $record->calculated_ot_minutes;
                        }
                        if ($get('request_type') === 'comp_off') {
                            $values['comp_off_days'] = $record->calculated_comp_off_days;
                        }
                        $set('proposed_values', $values);
                        $set('previous_values', $values);
                    }
                }),
            KeyValue::make('previous_values')->label('Current values at submission')->disabled()->dehydrated(false),
            Group::make()->schema(function (Get $get): array {
                $fields = [];
                foreach (HrChangeService::rules($get('request_type') ?? '') as $name => $rules) {
                    $key = 'proposed_values.'.$name;
                    $field = str_contains($rules, 'boolean') ? Toggle::make($key)
                        : (str_contains($rules, 'date') ? (in_array($name, ['check_in', 'check_out'], true) ? DateTimePicker::make($key) : DatePicker::make($key)) : TextInput::make($key));
                    $fields[] = $field->label(str($name)->replace('_', ' ')->title()->toString());
                }

                return $fields;
            })->columns(2)->columnSpanFull(),
            Textarea::make('reason')->required()->maxLength(2000)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Request'),
            TextColumn::make('employee.full_name')->label('Employee'),
            TextColumn::make('request_type')->formatStateUsing(fn (string $state): string => HrChangeService::types()[$state] ?? $state),
            TextColumn::make('approval_status')->badge(),
            TextColumn::make('applied_at')->dateTime(),
            TextColumn::make('reason')->limit(60),
        ])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageHrChangeRequests::route('/')];
    }
}

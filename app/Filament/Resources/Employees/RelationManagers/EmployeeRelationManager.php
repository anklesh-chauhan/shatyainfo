<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Services\HR\EmployeeRegister;
use App\Services\HR\EmployeeRegisterFields;
use App\Services\HR\HrChangeService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EmployeeRelationManager extends RelationManager
{
    protected static string $employeeSection;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $service = app(EmployeeRegister::class);

        return $service->canReadSection(auth()->user(), static::$employeeSection)
            && $service->visible(auth()->user())->whereKey($ownerRecord->id)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(EmployeeForm::fields(static::$employeeSection, $this->getOwnerRecord()));
    }

    private function canWrite(string $ability): bool
    {
        return app(EmployeeRegister::class)->canWriteSection(auth()->user(), $this->getOwnerRecord(), static::$employeeSection, $ability);
    }

    private function change(?Model $row, ?array $data, string $revision): ?Model
    {
        return app(EmployeeRegister::class)->changeSection(auth()->user(), $this->getOwnerRecord()->id, static::$employeeSection, $row?->id, $data, $revision);
    }

    public function table(Table $table): Table
    {
        $section = static::$employeeSection;
        $columns = array_slice(array_keys(EmployeeRegisterFields::fields($section)), 0, 5);
        if ($section === 'positions') {
            $columns = ['name', 'pivot.is_primary'];
        }

        return $table->columns(array_map(fn (string $field): TextColumn => TextColumn::make($field)->label(EmployeeRegisterFields::fields($section)[$field]['label'] ?? str($field)->headline()->toString()), $columns))
            ->headerActions([CreateAction::make()->label($section === 'positions' ? 'Assign position' : 'Create')
                ->authorize(fn (): bool => $this->canWrite($section === 'positions' ? 'Update' : 'Create'))
                ->visible(fn (): bool => ! in_array($section, ['employment', 'statutory'], true) || ! $this->getOwnerRecord()->{static::$relationship}()->exists())
                ->using(fn (array $data): Model => $this->change(null, $data, $data['employee_revision']))])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => $this->canWrite('Update'))
                    ->visible(fn (Model $record): bool => $section === 'positions' || app(EmployeeRegister::class)->unlocked(auth()->user(), $record))
                    ->mutateRecordDataUsing(function (Model $record, array $data) use ($section): array {
                        $data['employee_revision'] = app(EmployeeRegister::class)->revision($this->getOwnerRecord()->fresh());
                        if ($section === 'employment') {
                            $data['org_unit_ids'] = $record->organizationalUnits()->pluck('organizational_units.id')->all();
                        } elseif ($section === 'positions') {
                            $data['position_id'] = $record->id;
                            $data['is_primary'] = (bool) $record->pivot->is_primary;
                        }

                        return $data;
                    })
                    ->using(fn (Model $record, array $data): Model => $this->change($record, $data, $data['employee_revision'])),
                DeleteAction::make()->label($section === 'positions' ? 'Detach' : 'Delete')
                    ->authorize(fn (): bool => $this->canWrite($section === 'positions' ? 'Update' : 'Delete'))
                    ->visible(fn (Model $record): bool => ! in_array($section, ['employment', 'statutory'], true) && ($section === 'positions' || app(EmployeeRegister::class)->unlocked(auth()->user(), $record)))
                    ->schema([Hidden::make('employee_revision')->default(fn (): string => app(EmployeeRegister::class)->revision($this->getOwnerRecord()->fresh()))])
                    ->using(fn (Model $record, array $data): bool => $this->change($record, null, $data['employee_revision']) === null),
                Action::make('request_hr_change')->label('Request HR change')->visible(fn (): bool => $section === 'employment' && app(EmployeeRegister::class)->canRequestChange(auth()->user(), $this->getOwnerRecord()))
                    ->schema([...($section === 'employment' ? EmployeeForm::fields('employment', change: true) : []), Hidden::make('revision'), Textarea::make('reason')->required()->maxLength(2000)])
                    ->fillForm(fn (Model $record): array => $record->only(array_keys(HrChangeService::rules('employment'))) + ['revision' => app(EmployeeRegister::class)->revision($this->getOwnerRecord()->fresh())])
                    ->action(function (array $data): void {
                        app(EmployeeRegister::class)->requestChange(auth()->user(), $this->getOwnerRecord()->id, 'employment', ['revision' => $data['revision'], 'reason' => $data['reason'], 'values' => Arr::only($data, array_keys(HrChangeService::rules('employment')))]);
                        Notification::make()->title('HR change submitted')->success()->send();
                    }),
            ])->toolbarActions([BulkAction::make('delete')->label('Delete selected')->requiresConfirmation()
            ->visible(fn (): bool => ! in_array($section, ['employment', 'statutory'], true) && $this->canWrite($section === 'positions' ? 'Update' : 'Delete') && auth()->user()->can('DeleteAny:'.class_basename(EmployeeRegister::SECTIONS[$section][1])))
            ->schema([Hidden::make('revision')->default(fn (): string => app(EmployeeRegister::class)->revision($this->getOwnerRecord()->fresh()))])
            ->action(fn (Collection $records, array $data) => app(EmployeeRegister::class)->deleteRows(auth()->user(), $this->getOwnerRecord()->id, $section, $records->modelKeys(), $data['revision']))->deselectRecordsAfterCompletion()]);
    }
}

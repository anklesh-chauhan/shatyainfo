<?php

namespace App\Filament\Resources\Positions;

use App\Enums\PositionStatus;
use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Pages\ViewPosition;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Models\Position;
use App\Services\HR\PositionRegister;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PositionResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Position::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?string $navigationLabel = 'Positions';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Position Details')->columns(2)->schema(PositionForm::fields())
            ->disabled(fn (?Position $record): bool => $record !== null && ! app(PositionRegister::class)->canChange(auth()->user(), $record))]);
    }

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(PositionRegister::class)->canRead(auth()->user());
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && app(PositionRegister::class)->canRead(auth()->user());
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && app(PositionRegister::class)->canChange(auth()->user(), $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $service = app(PositionRegister::class);
        $relations = ['approval'];
        foreach (['reportsTo' => 'parents', 'territories' => 'territories', 'location' => 'locations', 'division' => 'divisions', 'department' => 'departments', 'jobTitle' => 'titles', 'jobGrade' => 'grades', 'organizationalUnits' => 'org-units'] as $relation => $kind) {
            $relations[$relation] = fn ($query) => $query->whereIn($query->getModel()->qualifyColumn('id'), $service->catalog(auth()->user(), $kind)->select('id'));
        }

        return $service->visible(auth()->user())->with($relations);
    }

    public static function recordAction(string $action, string $label): Action
    {
        return Action::make($action)->label($label)->color($action === 'delete' ? 'danger' : 'primary')->requiresConfirmation()
            ->schema([Hidden::make('revision')])->fillForm(fn (Position $record): array => ['revision' => app(PositionRegister::class)->revision($record->fresh())])
            ->visible(fn (Position $record): bool => app(PositionRegister::class)->canAct(auth()->user(), $record, $action))
            ->action(function (Position $record, array $data) use ($action): void {
                try {
                    app(PositionRegister::class)->act(auth()->user(), [$record->id], $action, $data['revision']);
                    Notification::make()->title('Position updated')->success()->send();
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    Notification::make()->title($exception->getMessage())->danger()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('level')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reportsTo.name')
                    ->label('Reports To')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('territories.name')
                    ->label('Territories')
                    ->listWithLineBreaks()
                    ->searchable(),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('division.name')
                    ->label('Division')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('department.department_name')
                    ->label('Department')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('jobTitle.title')
                    ->label('Job Title')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('jobGrade.grade_name')
                    ->label('Job Grade')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('organizationalUnits.name')
                    ->label('Org. Units')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PositionStatus::class),
                SelectFilter::make('territories')
                    ->relationship('territories', 'name', modifyQueryUsing: fn ($query) => $query->whereIn($query->getModel()->qualifyColumn('id'), app(PositionRegister::class)->catalog(auth()->user(), 'territories')->select('id')))
                    ->multiple()
                    ->label('Filter by Territory'),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reports_to_position_id')
                    ->label('Reports To')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'parents')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('division_ou_id')
                    ->label('Division')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'divisions')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('department_id')
                    ->label('Department')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'departments')->orderBy('department_name')->pluck('department_name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('job_title_id')
                    ->label('Job Title')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'titles')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('job_grade_id')
                    ->label('Job Grade')
                    ->options(fn (): array => app(PositionRegister::class)->catalog(auth()->user(), 'grades')->orderBy('grade_name')->pluck('grade_name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('organizationalUnits')
                    ->relationship('organizationalUnits', 'name', modifyQueryUsing: fn ($query) => $query->whereIn($query->getModel()->qualifyColumn('id'), app(PositionRegister::class)->catalog(auth()->user(), 'org-units')->select('id')))
                    ->multiple()
                    ->preload()
                    ->label('Organizational Units'),
            ])->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::recordAction('submit', 'Send for Approval'),
                self::recordAction('delete', 'Delete'),
            ])
            ->toolbarActions([
                BulkAction::make('delete')->label('Delete')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()->can('DeleteAny:Position'))->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        try {
                            app(PositionRegister::class)->act(auth()->user(), $records->modelKeys(), 'delete', bulk: true);
                        } catch (HttpException $exception) {
                            if ($exception->getStatusCode() !== 409) {
                                throw $exception;
                            }
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'create' => CreatePosition::route('/create'),
            'view' => ViewPosition::route('/{record}'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }
}

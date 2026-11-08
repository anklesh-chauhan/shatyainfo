<?php

namespace App\Filament\Resources\TerritorySalesAssignments;

use App\Filament\Resources\TerritorySalesAssignments\Pages\ManageTerritorySalesAssignments;
use App\Models\TerritorySalesAssignment;
use App\Services\Field\SalesPerformanceService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TerritorySalesAssignmentResource extends Resource
{
    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Territory Sales Assignments';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers & Territories';

    protected static ?string $model = TerritorySalesAssignment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->can('View:SalesTargets') || auth()->user()?->can('Manage:SalesTargets'));
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('Manage:SalesTargets') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('territory_id', app(SalesPerformanceService::class)->territories(auth()->user())->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('territory_id')->label('Territory')->options(fn () => app(SalesPerformanceService::class)->territories(auth()->user())->pluck('name', 'id'))->required(),
                Select::make('user_id')->label('Representative')->relationship('user', 'name')->searchable()->required(),
                DatePicker::make('effective_from')->label('Effective date')->default(today())->required(),
                Textarea::make('reason')->label('Assignment / initial history reason')->required()->maxLength(2000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('territory.name')->searchable(),
                TextColumn::make('user.name')->label('Representative'),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Ongoing'),
                TextColumn::make('reason')->limit(80),
            ])
            ->filters([
                //
            ])
            ->recordActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTerritorySalesAssignments::route('/'),
        ];
    }
}

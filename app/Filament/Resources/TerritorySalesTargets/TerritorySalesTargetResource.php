<?php

namespace App\Filament\Resources\TerritorySalesTargets;

use App\Filament\Resources\TerritorySalesTargets\Pages\ManageTerritorySalesTargets;
use App\Models\TerritorySalesTarget;
use App\Services\Field\SalesPerformanceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TerritorySalesTargetResource extends Resource
{
    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Territory Sales Targets';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & CRM';

    protected static ?string $model = TerritorySalesTarget::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

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
        return static::canCreate() && $record->starts_on->gt(today());
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesPerformanceService::class)->visibleTargets(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('territory_id')->label('Territory')->options(fn () => app(SalesPerformanceService::class)->territories(auth()->user())->pluck('name', 'id'))->required()->disabledOn('edit'),
                Select::make('period_type')->options(['monthly' => 'Monthly', 'quarterly' => 'Quarterly (3 months)', 'annual' => 'Annual (12 months)'])->required()->disabledOn('edit'),
                DatePicker::make('starts_on')->label('First month starts on')->default(today()->startOfMonth())->required()->disabledOn('edit'),
                TextInput::make('target_value_lakhs')->label('Value target (₹ lakhs)')->required()->regex('/^\d{1,8}(\.\d{1,7})?$/')
                    ->afterStateHydrated(function ($component, ?TerritorySalesTarget $record): void {
                        if ($record) {
                            $component->state(number_format($record->target_value_paise / 10000000, 7, '.', ''));
                        }
                    }),
                TextInput::make('target_quantity')->label('Quantity target (normalized base units)')->numeric()->minValue(0.000001)->maxValue(999999999999)->required(),
                Textarea::make('note')->label('Planning / revision reason')->required()->maxLength(2000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('territory.name')->searchable(),
                TextColumn::make('period_type')->badge(),
                TextColumn::make('starts_on')->date()->sortable(),
                TextColumn::make('ends_on')->date(),
                TextColumn::make('target_value_paise')->label('Value (₹ lakhs)')->formatStateUsing(fn ($state) => number_format($state / 10000000, 2)),
                TextColumn::make('target_quantity')->label('Base units')->numeric(decimalPlaces: 3),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('performance')->label('Achievement dashboard')->modalContent(fn (TerritorySalesTarget $record) => view('filament.sfa.target-performance', ['data' => app(SalesPerformanceService::class)->dashboard(auth()->user(), $record)]))->modalSubmitAction(false)->modalWidth('5xl'),
                EditAction::make()->using(fn (TerritorySalesTarget $record, array $data) => app(SalesPerformanceService::class)->reviseTarget(auth()->user(), $record, $data)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTerritorySalesTargets::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\RcpaCompetitors;

use App\Filament\Resources\RcpaCompetitors\Pages\ManageRcpaCompetitors;
use App\Models\AccountReportingMaster;
use App\Models\RcpaCompetitor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class RcpaCompetitorResource extends Resource
{
    protected static ?string $model = RcpaCompetitor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('Manage:RcpaCompetitor') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('therapy_area_id')->label('Therapeutic segment')->options(fn () => AccountReportingMaster::query()->where('kind', 'therapy_area')->where('is_active', true)->pluck('name', 'id'))->required()->disabledOn('edit'),
                TextInput::make('rank')->numeric()->integer()->minValue(1)->maxValue(5)->required()->disabledOn('edit')
                    ->rules(fn ($get, $record) => [Rule::unique('rcpa_competitors', 'rank')->where('therapy_area_id', $get('therapy_area_id'))->ignore($record?->id)]),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('company_name')->maxLength(255),
                Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('therapyArea.name')->label('Segment'),
                TextColumn::make('rank'),
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRcpaCompetitors::route('/'),
        ];
    }
}

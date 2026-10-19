<?php

namespace App\Filament\Resources\AccountMasters\RelationManagers;

use App\Models\OrganizationalUnit;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DivisionProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'divisionProfiles';

    protected static ?string $title = 'Division Segmentation & Potential';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('division_ou_id')->label('Division')->relationship('division', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true)->whereHas('typeMaster', fn ($q) => $q->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class)))->searchable()->preload()->helperText('Leave blank for the account-wide profile.'),
            Select::make('rating_type_id')->label('Potential Segment')->relationship('ratingType', 'name', modifyQueryUsing: fn ($query) => $query->where('is_lifecycle', false))->required()->searchable()->preload(),
            DatePicker::make('effective_from')->required(), DatePicker::make('effective_to')->afterOrEqual('effective_from'),
            TextInput::make('potential_value')->numeric()->minValue(0),
            Select::make('potential_unit_id')->label('Potential Unit')->relationship('potentialUnit', 'name', modifyQueryUsing: fn ($query) => $query->where('kind', 'potential_unit')->where('is_active', true))->searchable()->preload()->requiredWith('potential_value'),
            Select::make('potential_period')->options(['monthly' => 'Per Month', 'quarterly' => 'Per Quarter', 'annual' => 'Per Year'])->requiredWith('potential_value'),
            TextInput::make('currency')->maxLength(3)->regex('/^[A-Z]{3}$/')->helperText('Three-letter currency code, required for monetary potential.'),
            DatePicker::make('assessed_on')->maxDate(today())->requiredWith('potential_value'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('division.name')->placeholder('Account-wide'), TextColumn::make('ratingType.name')->label('Segment'),
            TextColumn::make('effective_from')->date(), TextColumn::make('effective_to')->date()->placeholder('Open-ended'),
            TextColumn::make('potential_value')->numeric(), TextColumn::make('potentialUnit.name'), TextColumn::make('currency'), TextColumn::make('potential_period'), TextColumn::make('assessed_on')->date(),
        ])->headerActions([CreateAction::make()->authorize(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))])
            ->recordActions([EditAction::make()->authorize(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))])->defaultSort('effective_from', 'desc');
    }
}

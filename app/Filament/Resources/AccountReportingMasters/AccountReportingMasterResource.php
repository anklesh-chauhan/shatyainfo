<?php

namespace App\Filament\Resources\AccountReportingMasters;

use App\Models\AccountReportingMaster;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccountReportingMasterResource extends Resource
{
    protected static ?string $model = AccountReportingMaster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts & Companies';

    protected static ?string $navigationLabel = 'Account Reporting Masters';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('ViewAny:RatingType') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('Create:RatingType') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('Update:RatingType') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->options(AccountReportingMaster::KINDS)->required()->disabledOn('edit')->live(),
            Toggle::make('is_monetary')->label('Monetary potential unit')->disabledOn('edit')->visible(fn (Get $get): bool => $get('kind') === 'potential_unit'),
            Toggle::make('is_active')->default(true),
            TextInput::make('code')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('kind')->badge(), TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAccountReportingMasters::route('/'), 'create' => Pages\CreateAccountReportingMaster::route('/create'), 'edit' => Pages\EditAccountReportingMaster::route('/{record}/edit')];
    }
}

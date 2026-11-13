<?php

namespace App\Filament\Resources\AccountStatuses;

use App\Filament\Clusters\GlobalConfiguration\CustomerConfigurationCluster;
use App\Models\AccountStatus;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccountStatusResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?int $navigationSort = 10;

    protected static ?string $cluster = CustomerConfigurationCluster::class;

    protected static ?string $model = AccountStatus::class;

    protected static ?string $navigationLabel = 'Account Statuses';

    public static function canViewAny(): bool
    {
        return parent::canViewAny()
            || (auth()->user()?->can('ViewAny:RatingType') ?? false);
    }

    public static function canCreate(): bool
    {
        return parent::canCreate()
            || (auth()->user()?->can('Create:RatingType') ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record)
            || (auth()->user()?->can('Update:RatingType') ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAccountStatuses::route('/'), 'create' => Pages\CreateAccountStatus::route('/create'), 'edit' => Pages\EditAccountStatus::route('/{record}/edit')];
    }
}

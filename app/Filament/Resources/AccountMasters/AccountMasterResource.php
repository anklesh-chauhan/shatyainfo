<?php

namespace App\Filament\Resources\AccountMasters;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\AccountMasterBankDetailResource\RelationManagers\BankDetailRelationManager;
use App\Filament\Resources\AccountMasterCreditDetailResource\RelationManagers\CreditDetailRelationManager;
use App\Filament\Resources\AccountMasterGSTDetailResource\RelationManagers\GstDetailRelationManager;
use App\Filament\Resources\AccountMasters\Pages\AccountMasterInfolist;
use App\Filament\Resources\AccountMasters\Pages\CreateAccountMaster;
use App\Filament\Resources\AccountMasters\Pages\EditAccountMaster;
use App\Filament\Resources\AccountMasters\Pages\ListAccountMasters;
use App\Filament\Resources\AccountMasters\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\AccountMasters\RelationManagers\ContactDetailsRelationManager;
use App\Filament\Resources\AccountMasterStatutoryDetailResource\RelationManagers\StatutoryDetailRelationManager;
use App\Filament\Resources\BaseResource;
use App\Models\AccountMaster;
use App\Traits\CreateAccountMasterTrait;
use App\Traits\HasSafeGlobalSearch;
use App\Traits\HasVisibilityScope;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AccountMasterResource extends BaseResource
{
    use CreateAccountMasterTrait;
    use HasSafeGlobalSearch;
    use HasVisibilityScope;

    protected static ?string $model = AccountMaster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts & Companies';

    protected static ?int $navigationSort = 190;

    protected static ?string $navigationLabel = 'Account Masters';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ...self::getCreateAccountMasterTraitFields(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountMasterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('account_code')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('external_reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone_number')
                    ->searchable(),
                TextColumn::make('industryType.name')
                    ->sortable(),
                TextColumn::make('region.name')
                    ->sortable(),
                TextColumn::make('specialty.name')->sortable(),
                TextColumn::make('therapyArea.name')->sortable(),
                TextColumn::make('commission')
                    ->numeric(decimalPlaces: 2)->suffix('%'),
                TextColumn::make('accountStatus.name')->label('Lifecycle Status')->sortable(),
                TextColumn::make('ratingType.name')->label('Potential Rating')->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('specialty_id')->relationship('specialty', 'name'),
                SelectFilter::make('therapy_area_id')->relationship('therapyArea', 'name'),
                SelectFilter::make('account_status_id')->label('Lifecycle Status')->relationship('accountStatus', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
                ApprovalAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkApprovalAction::make(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DivisionProfilesRelationManager::class,
            RelationManagers\CallTargetsRelationManager::class,
            RelationManagers\AssignmentHistoryRelationManager::class,
            AddressesRelationManager::class,
            ContactDetailsRelationManager::class,
            BankDetailRelationManager::class,
            CreditDetailRelationManager::class,
            GstDetailRelationManager::class,
            StatutoryDetailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountMasters::route('/'),
            'create' => CreateAccountMaster::route('/create'),
            'edit' => EditAccountMaster::route('/{record}/edit'),
            'view' => AccountMasterInfolist::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}

<?php

namespace App\Filament\Resources\TenantUsers;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\UserAndAccessCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\TenantUsers\Pages\CreateTenantUser;
use App\Filament\Resources\TenantUsers\Pages\EditTenantUser;
use App\Filament\Resources\TenantUsers\Pages\ListTenantUsers;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantUserResource extends BaseResource
{
    protected static ?string $cluster = UserAndAccessCluster::class;

    use HasSafeGlobalSearch;

    protected static ?string $model = TenantUser::class;

    public static function canAccess(): bool
    {
        if (Tenant::checkCurrent()) {
            return false;
        }

        return parent::canAccess();
    }

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Tenant Users';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn ($state) => bcrypt($state))
                    ->hiddenOn('edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('email')->sortable()->searchable(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantUsers::route('/'),
            'create' => CreateTenantUser::route('/create'),
            'edit' => EditTenantUser::route('/{record}/edit'),
        ];
    }
}

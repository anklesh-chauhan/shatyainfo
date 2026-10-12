<?php

namespace App\Filament\Resources\Tenants;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\UserAndAccessCluster;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    use HasSafeGlobalSearch;

    protected static ?string $model = Tenant::class;

    protected static ?string $cluster = UserAndAccessCluster::class;

    protected static ?int $navigationSort = 1000;

    protected static ?string $navigationLabel = 'Tenants';

    public static function canAccess(): bool
    {
        if (Tenant::checkCurrent()) {
            return false;
        }

        return parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('domain')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('database')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('owner_name')
                    ->label('Owner name')
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('create')
                    ->dehydrated(false),
                TextInput::make('owner_email')
                    ->label('Owner email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('create')
                    ->dehydrated(false),
                TextInput::make('owner_password')
                    ->label('Owner password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(12)
                    ->visibleOn('create')
                    ->dehydrated(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('domain')
                    ->searchable(),
                TextColumn::make('database')
                    ->searchable(),
                TextColumn::make('provisioning_status')
                    ->label('Setup')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        'provisioning' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('provisioning_error')
                    ->label('Setup error')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(),
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
                //
            ])
            ->poll('5s')
            ->recordActions([
                Action::make('retrySetup')
                    ->label('Retry setup')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Tenant $record): bool => $record->provisioning_status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (Tenant $record): void {
                        $record->forceFill([
                            'provisioning_status' => 'pending',
                            'provisioning_error' => null,
                        ])->saveQuietly();

                        ProvisionTenant::dispatch($record->id);
                    }),
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
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}

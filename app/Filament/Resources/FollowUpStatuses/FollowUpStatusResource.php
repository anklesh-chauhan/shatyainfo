<?php

namespace App\Filament\Resources\FollowUpStatuses;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\FollowpConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\FollowUpStatuses\Pages\CreateFollowUpStatus;
use App\Filament\Resources\FollowUpStatuses\Pages\EditFollowUpStatus;
use App\Filament\Resources\FollowUpStatuses\Pages\ListFollowUpStatuses;
use App\Models\FollowUpStatus;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpStatusResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = FollowUpStatus::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $cluster = FollowpConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Status';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
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
            'index' => ListFollowUpStatuses::route('/'),
            'create' => CreateFollowUpStatus::route('/create'),
            'edit' => EditFollowUpStatus::route('/{record}/edit'),
        ];
    }
}

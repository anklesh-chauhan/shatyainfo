<?php

namespace App\Filament\Resources\FollowUpPriorities;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\FollowpConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\FollowUpPriorities\Pages\CreateFollowUpPriority;
use App\Filament\Resources\FollowUpPriorities\Pages\EditFollowUpPriority;
use App\Filament\Resources\FollowUpPriorities\Pages\ListFollowUpPriorities;
use App\Models\FollowUpPriority;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpPriorityResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = FollowUpPriority::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $cluster = FollowpConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Priorities';

    protected static ?int $navigationSort = 21;

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
            'index' => ListFollowUpPriorities::route('/'),
            'create' => CreateFollowUpPriority::route('/create'),
            'edit' => EditFollowUpPriority::route('/{record}/edit'),
        ];
    }
}

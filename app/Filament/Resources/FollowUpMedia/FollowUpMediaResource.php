<?php

namespace App\Filament\Resources\FollowUpMedia;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\FollowpConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\FollowUpMedia\Pages\CreateFollowUpMedia;
use App\Filament\Resources\FollowUpMedia\Pages\EditFollowUpMedia;
use App\Filament\Resources\FollowUpMedia\Pages\ListFollowUpMedia;
use App\Models\FollowUpMedia;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpMediaResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = FollowUpMedia::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $cluster = FollowpConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Media';

    protected static ?int $navigationSort = 20;

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
            'index' => ListFollowUpMedia::route('/'),
            'create' => CreateFollowUpMedia::route('/create'),
            'edit' => EditFollowUpMedia::route('/{record}/edit'),
        ];
    }
}

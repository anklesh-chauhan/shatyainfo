<?php

namespace App\Filament\Resources\FollowUpResults;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\FollowpConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\FollowUpResults\Pages\CreateFollowUpResult;
use App\Filament\Resources\FollowUpResults\Pages\EditFollowUpResult;
use App\Filament\Resources\FollowUpResults\Pages\ListFollowUpResults;
use App\Models\FollowUpResult;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpResultResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = FollowUpResult::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $cluster = FollowpConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 22;

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
            'index' => ListFollowUpResults::route('/'),
            'create' => CreateFollowUpResult::route('/create'),
            'edit' => EditFollowUpResult::route('/{record}/edit'),
        ];
    }
}

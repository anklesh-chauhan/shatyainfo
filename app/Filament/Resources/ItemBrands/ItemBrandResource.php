<?php

namespace App\Filament\Resources\ItemBrands;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\ItemConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ItemBrands\Pages\CreateItemBrand;
use App\Filament\Resources\ItemBrands\Pages\EditItemBrand;
use App\Filament\Resources\ItemBrands\Pages\ListItemBrands;
use App\Models\ItemBrand;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemBrandResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = ItemBrand::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = ItemConfigurationCluster::class;

    protected static ?int $navigationSort = 1003;

    protected static ?string $navigationLabel = 'Item Brands';

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
            'index' => ListItemBrands::route('/'),
            'create' => CreateItemBrand::route('/create'),
            'edit' => EditItemBrand::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Helpers\ModelHelper;
use App\Models\Category;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?int $navigationSort = 201;

    protected static ?string $navigationLabel = 'Category';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('alias')
                    ->maxLength(255),
                Select::make('parent_id')
                    ->label('Parent Category')
                    ->options(Category::all()->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),
                Textarea::make('description')
                    ->maxLength(65535),
                FileUpload::make('image_path')
                    ->image()
                    ->directory('categories'),
                Select::make('modelable_type')
                    ->label('Category Type')
                    ->options(ModelHelper::getModelOptions()) // Dynamic Model Names
                    ->nullable(),
                TextInput::make('modelable_id')
                    ->label('Modelable ID')
                    ->numeric()
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('alias')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->label('Parent Category')
                    ->sortable(),
                ImageColumn::make('image_path')
                    ->label('Image'),
                TextColumn::make('modelable_type')
                    ->label('Type')
                    ->sortable(),
                TextColumn::make('modelable_id')
                    ->label('Modelable ID')
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Deleted At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('modelable_type')
                    ->options([
                        'App\Models\ItemMaster' => 'Item',
                        'App\Models\Company' => 'Company',
                        // Add other modelable types as needed
                    ])
                    ->label('Filter by Type'),
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
                RestoreAction::make()
                    ->visible(fn ($record) => $record !== null && $record->trashed())
                    ->requiresConfirmation(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            // Define relation managers here if needed (e.g., ChildrenRelationManager)
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withTrashed(); // Include soft-deleted records
    }
}

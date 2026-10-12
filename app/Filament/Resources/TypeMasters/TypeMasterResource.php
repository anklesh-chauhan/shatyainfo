<?php

namespace App\Filament\Resources\TypeMasters;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\TypeMasters\Pages\CreateTypeMaster;
use App\Filament\Resources\TypeMasters\Pages\EditTypeMaster;
use App\Filament\Resources\TypeMasters\Pages\ListTypeMasters;
use App\Helpers\ModelHelper;
use App\Models\TypeMaster;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TypeMasterResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = TypeMaster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?int $navigationSort = 200;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(64),
                TextInput::make('description')
                    ->maxLength(255),

                Select::make('typeable_type')
                    ->label('Module Type')
                    ->options(ModelHelper::getModelOptions())
                    ->required()
                    ->live(),

                Select::make('parent_id')
                    ->label('Parent Type')
                    ->options(fn (Get $get) => filled($get('typeable_type'))
                            ? TypeMaster::where('typeable_type', $get('typeable_type'))
                                ->whereNull('parent_id')
                                ->pluck('name', 'id')
                            : []
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->visible(fn (Get $get) => filled($get('typeable_type')))
                    ->helperText('Leave blank to create a root type'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),

                TextColumn::make('parent.name')
                    ->label('Parent Type')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('typeable_type')
                    ->label('Module')
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->sortable()
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
            'index' => ListTypeMasters::route('/'),
            'create' => CreateTypeMaster::route('/create'),
            'edit' => EditTypeMaster::route('/{record}/edit'),
        ];
    }
}

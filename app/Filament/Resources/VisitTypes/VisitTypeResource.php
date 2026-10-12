<?php

namespace App\Filament\Resources\VisitTypes;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\SalesMarketingConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\VisitTypes\Pages\CreateVisitType;
use App\Filament\Resources\VisitTypes\Pages\EditVisitType;
use App\Filament\Resources\VisitTypes\Pages\ListVisitTypes;
use App\Models\VisitType;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VisitTypeResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = VisitType::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = SalesMarketingConfigurationCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Visit Types';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('description')
                    ->maxLength(65535),
                TextInput::make('sort_order')
                    ->numeric(),
                IconColumn::make('is_active')
                    ->default(true)
                    ->boolean(),
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
            'index' => ListVisitTypes::route('/'),
            'create' => CreateVisitType::route('/create'),
            'edit' => EditVisitType::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\VisitPurposes;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\SalesMarketingConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\VisitPurposes\Pages\CreateVisitPurpose;
use App\Filament\Resources\VisitPurposes\Pages\EditVisitPurpose;
use App\Filament\Resources\VisitPurposes\Pages\ListVisitPurposes;
use App\Models\VisitPurpose;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VisitPurposeResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = VisitPurpose::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $cluster = SalesMarketingConfigurationCluster::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Visit Puroposes';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('visit_type_id')
                    ->relationship('visitType', 'name'),
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
                TextColumn::make('visitType.name')
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
            'index' => ListVisitPurposes::route('/'),
            'create' => CreateVisitPurpose::route('/create'),
            'edit' => EditVisitPurpose::route('/{record}/edit'),
        ];
    }
}

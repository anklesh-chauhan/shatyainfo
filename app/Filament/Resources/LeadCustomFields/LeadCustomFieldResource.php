<?php

namespace App\Filament\Resources\LeadCustomFields;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\LeadConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\LeadCustomFields\Pages\CreateLeadCustomField;
use App\Filament\Resources\LeadCustomFields\Pages\EditLeadCustomField;
use App\Filament\Resources\LeadCustomFields\Pages\ListLeadCustomFields;
use App\Models\LeadCustomField;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeadCustomFieldResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = LeadCustomField::class;

    protected static ?string $cluster = LeadConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Lead Custom Fields';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->options([
                        'text' => 'Text',
                        'number' => 'Number',
                        'date' => 'Date',
                        'email' => 'Email',
                    ])
                    ->required(),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable(),
                TextColumn::make('type')
                    ->searchable(),
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
            'index' => ListLeadCustomFields::route('/'),
            'create' => CreateLeadCustomField::route('/create'),
            'edit' => EditLeadCustomField::route('/{record}/edit'),
        ];
    }
}

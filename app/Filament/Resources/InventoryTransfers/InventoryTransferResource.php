<?php

namespace App\Filament\Resources\InventoryTransfers;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\InventoryTransfers\Pages\ManageInventoryTransfers;
use App\Models\InventoryTransfer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryTransferResource extends BaseResource
{
    protected static ?string $model = InventoryTransfer::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Stock Transfers';

    protected static ?string $recordTitleAttribute = 'transfer_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('item_master_id')->relationship('item', 'item_name')->searchable()->preload()->required(),
            Select::make('from_location_master_id')->relationship('fromLocation', 'name')->searchable()->preload()->required(),
            Select::make('to_location_master_id')->relationship('toLocation', 'name')->searchable()->preload()->different('from_location_master_id')->required(),
            TextInput::make('quantity')->numeric()->minValue(0.001)->required(),
            TextInput::make('unit_cost')->numeric()->minValue(0)->required(),
            Textarea::make('remarks'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('transfer_number')->searchable(),
            TextColumn::make('item.item_name'),
            TextColumn::make('fromLocation.name')->label('From'),
            TextColumn::make('toLocation.name')->label('To'),
            TextColumn::make('quantity'),
            TextColumn::make('status')->badge(),
            TextColumn::make('approval.approval_status')->label('Approval')->badge()->placeholder('Not submitted'),
        ])->recordActions([
            EditAction::make()->visible(fn (InventoryTransfer $record): bool => $record->isEditable()),
            ApprovalAction::make()->authorize(fn (InventoryTransfer $record): bool => auth()->user()->can('update', $record)),
            Action::make('post')->label('Post Transfer')->requiresConfirmation()
                ->authorize(fn (InventoryTransfer $record): bool => auth()->user()->can('update', $record))
                ->visible(fn (InventoryTransfer $record): bool => ! $record->isPosted() && $record->canProceedAfterApproval())
                ->action(function (InventoryTransfer $record): void {
                    try {
                        $record->post();
                        Notification::make()->title('Transfer posted')->success()->send();
                    } catch (\Throwable $exception) {
                        Notification::make()->title('Unable to post transfer')->body($exception->getMessage())->danger()->send();
                    }
                }),
            DeleteAction::make()->visible(fn (InventoryTransfer $record): bool => $record->isEditable()),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInventoryTransfers::route('/')];
    }
}

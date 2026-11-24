<?php

namespace App\Filament\Resources\InventoryTransfers;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\InventoryTransfers\Pages\ManageInventoryTransfers;
use App\Models\InventoryTransfer;
use App\Services\Inventory\StockTransferRegister;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryTransferResource extends BaseResource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 60;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Purchasing';

    protected static ?string $model = InventoryTransfer::class;

    protected static ?string $navigationLabel = 'Stock Transfers';

    protected static ?string $recordTitleAttribute = 'transfer_number';

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(StockTransferRegister::class)->canRead(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            Select::make('item_master_id')->options(fn (): array => app(StockTransferRegister::class)->catalog(auth()->user(), 'items')->pluck('item_name', 'id')->all())->searchable()->preload()->required(),
            Select::make('from_location_master_id')->options(fn (): array => app(StockTransferRegister::class)->catalog(auth()->user(), 'locations')->pluck('name', 'id')->all())->searchable()->preload()->required(),
            Select::make('to_location_master_id')->options(fn (): array => app(StockTransferRegister::class)->catalog(auth()->user(), 'locations')->pluck('name', 'id')->all())->searchable()->preload()->different('from_location_master_id')->required(),
            TextInput::make('quantity')->numeric()->minValue(0.001)->required(),
            TextInput::make('unit_cost')->numeric()->minValue(0)->required(),
            Textarea::make('remarks')->maxLength(5000),
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
            ViewAction::make(),
            EditAction::make()->visible(fn (InventoryTransfer $record): bool => $record->isEditable())
                ->fillForm(fn (InventoryTransfer $record): array => [...$record->getAttributes(), 'revision' => app(StockTransferRegister::class)->revision($record)])
                ->using(fn (InventoryTransfer $record, array $data, EditAction $action): InventoryTransfer => self::persist($record, $data, $action)),
            self::transferAction('submit', 'Send for Approval'),
            self::transferAction('post', 'Post Transfer'),
            self::transferAction('delete', 'Delete'),
        ]);
    }

    public static function persist(?InventoryTransfer $record, array $data, Action $action): InventoryTransfer
    {
        try {
            return app(StockTransferRegister::class)->save(auth()->user(), $record?->id, $data);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->send();
            $action->halt();
        }
    }

    private static function transferAction(string $action, string $label): Action
    {
        return Action::make($action)->label($label)->requiresConfirmation()
            ->schema([Hidden::make('revision')])
            ->fillForm(fn (InventoryTransfer $record): array => ['revision' => app(StockTransferRegister::class)->revision($record)])
            ->visible(fn (InventoryTransfer $record): bool => app(StockTransferRegister::class)->canAct(auth()->user(), $record, $action))
            ->action(function (InventoryTransfer $record, array $data) use ($action): void {
                try {
                    app(StockTransferRegister::class)->act(auth()->user(), $record->id, $action, $data['revision']);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();

                    return;
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    } Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Transfer updated')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageInventoryTransfers::route('/')];
    }
}

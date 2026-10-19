<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\GoodsReceiptNotes\GoodsReceiptNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\ApprovalSetting;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteLine;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve PO')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => ! ApprovalSetting::moduleRequiresApproval(PurchaseOrder::class) && $this->record->isEditable()
                    && $this->record->status !== PurchaseOrderStatus::Approved)
                ->action(function (): void {
                    $this->record->assertApprovalComplete();
                    $this->record->forceFill([
                        'status' => PurchaseOrderStatus::Approved,
                        'approval_status' => 'approved',
                    ])->save();

                    Notification::make()
                        ->title('Purchase order approved')
                        ->success()
                        ->send();
                }),

            Action::make('createGrn')
                ->label('Create GRN')
                ->icon('heroicon-o-truck')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseOrderStatus::Approved,
                    PurchaseOrderStatus::PartiallyReceived,
                ], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    $purchaseOrder = $this->record->loadMissing('lines.item');
                    $purchaseOrder->assertApprovalComplete();

                    $pendingLines = $purchaseOrder->lines->filter(
                        fn ($line): bool => $line->remainingQuantity() > 0
                    );

                    if ($pendingLines->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to receive')
                            ->body('All PO lines are fully received.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $grn = DB::transaction(function () use ($purchaseOrder, $pendingLines): GoodsReceiptNote {
                        $grn = GoodsReceiptNote::query()->create([
                            'purchase_order_id' => $purchaseOrder->id,
                            'supplier_id' => $purchaseOrder->supplier_id,
                            'location_master_id' => $purchaseOrder->location_master_id,
                            'receipt_date' => now()->toDateString(),
                        ]);

                        foreach ($pendingLines as $line) {
                            GoodsReceiptNoteLine::query()->create([
                                'goods_receipt_note_id' => $grn->id,
                                'purchase_order_line_id' => $line->id,
                                'item_master_id' => $line->item_master_id,
                                'quantity_received' => $line->remainingQuantity(),
                                'unit_cost' => $line->unit_price,
                            ]);
                        }

                        return $grn;
                    });

                    $this->redirect(GoodsReceiptNoteResource::getUrl('edit', ['record' => $grn]));
                }),

            ApprovalAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->isEditable()) {
            Notification::make()
                ->title('Purchase order is locked')
                ->body('Approved or received purchase orders cannot be edited.')
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
    }

    protected function canEdit(): bool
    {
        return true;
    }
}

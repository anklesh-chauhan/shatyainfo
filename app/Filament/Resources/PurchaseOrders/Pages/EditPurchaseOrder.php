<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\GoodsReceiptNotes\GoodsReceiptNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrderLine;
use App\Services\Inventory\PurchaseOrderRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->orderAction('submit', 'Send for Approval', 'primary'),
            $this->orderAction('approve', 'Approve PO', 'success'),
            $this->orderAction('create-grn', 'Create GRN', 'success'),
            $this->orderAction('cancel', 'Cancel PO', 'warning'),
            $this->orderAction('delete', 'Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('lines');
        $data['revision'] = app(PurchaseOrderRegister::class)->revision($this->record);
        $data['lines'] = $this->record->lines->map(fn (PurchaseOrderLine $line): array => $line->only([
            'id', 'item_master_id', 'quantity_ordered', 'quantity_received', 'unit_price', 'tax_rate', 'line_total', 'description',
        ]))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(PurchaseOrderRegister::class)->save(auth()->user(), $record->id, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        } catch (HttpException $exception) {
            $this->notifyConflict($exception);
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $this->fillForm();
    }

    protected function getFormActions(): array
    {
        return $this->record->isEditable() ? parent::getFormActions() : [$this->getCancelFormAction()];
    }

    private function orderAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(PurchaseOrderRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    $receipt = app(PurchaseOrderRegister::class)->act(
                        auth()->user(), [$this->record->id], $action, $this->data['revision']
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();

                    return;
                } catch (HttpException $exception) {
                    $this->notifyConflict($exception);

                    return;
                }

                if ($receipt !== null) {
                    $this->redirect(auth()->user()->can('update', $receipt)
                        ? GoodsReceiptNoteResource::getUrl('edit', ['record' => $receipt])
                        : GoodsReceiptNoteResource::getUrl('index'));

                    return;
                }
                if ($action === 'delete') {
                    $this->redirect(PurchaseOrderResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Purchase order updated')->success()->send();
            });
    }

    private function notifyConflict(HttpException $exception): void
    {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }
        Notification::make()->title($exception->getMessage())->danger()->send();
    }
}

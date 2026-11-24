<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Pages;

use App\Filament\Resources\GoodsReceiptNotes\GoodsReceiptNoteResource;
use App\Models\GoodsReceiptNoteLine;
use App\Services\Inventory\GoodsReceiptRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditGoodsReceiptNote extends EditRecord
{
    protected static string $resource = GoodsReceiptNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->receiptAction('submit', 'Send for Approval', 'primary'),
            $this->receiptAction('post', 'Post to Stock', 'success'),
            $this->receiptAction('delete', 'Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('lines');
        $data['revision'] = app(GoodsReceiptRegister::class)->revision($this->record);
        $data['lines'] = $this->record->lines->map(fn (GoodsReceiptNoteLine $line): array => $line->only([
            'id', 'purchase_order_line_id', 'item_master_id', 'quantity_received', 'unit_cost', 'batch_number', 'remarks',
        ]))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(GoodsReceiptRegister::class)->save(auth()->user(), $record->id, $data);
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

    private function receiptAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(GoodsReceiptRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(GoodsReceiptRegister::class)->act(
                        auth()->user(), [$this->record->id], $action, $this->data['revision']
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();

                    return;
                } catch (HttpException $exception) {
                    $this->notifyConflict($exception);

                    return;
                }

                if ($action === 'delete') {
                    $this->redirect(GoodsReceiptNoteResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Goods receipt updated')->success()->send();
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

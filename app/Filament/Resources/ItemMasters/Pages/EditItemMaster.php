<?php

namespace App\Filament\Resources\ItemMasters\Pages;

use App\Filament\Resources\ItemMasters\ItemMasterResource;
use App\Services\Inventory\ItemRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditItemMaster extends EditRecord
{
    protected static string $resource = ItemMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->issueAction('submit', 'Send for Approval', 'primary'),
            $this->issueAction('delete', 'Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['revision'] = app(ItemRegister::class)->revision($this->record);
        $data['tax_ids'] = $this->record->taxes()->pluck('taxes.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(ItemRegister::class)->save(auth()->user(), $record->id, $data);
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
        return app(ItemRegister::class)->canChange(auth()->user(), $this->record) ? parent::getFormActions() : [$this->getCancelFormAction()];
    }

    private function issueAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(ItemRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(ItemRegister::class)->act(
                        auth()->user(), [$this->record->id], $action, $this->data['revision']
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();

                    return;
                } catch (HttpException $exception) {
                    $this->notifyConflict($exception);

                    return;
                }

                if (in_array($action, ['delete', 'force-delete'], true)) {
                    $this->redirect(ItemMasterResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Item updated')->success()->send();
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

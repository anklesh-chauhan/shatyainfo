<?php

namespace App\Filament\Resources\LocationMasters\Pages;

use App\Filament\Resources\LocationMasters\LocationMasterResource;
use App\Services\Inventory\LocationRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditLocationMaster extends EditRecord
{
    protected static string $resource = LocationMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->locationAction('submit', 'Send for Approval', 'primary'),
            $this->locationAction('delete', 'Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['revision'] = app(LocationRegister::class)->revision($this->record);
        $data['territory_ids'] = $this->record->territories()->pluck('territories.id')->all();
        $data['contact_id'] = $this->record->contactDetail?->id;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(LocationRegister::class)->save(auth()->user(), $record->id, LocationMasterResource::adminInput($data, $record));
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
        return app(LocationRegister::class)->canChange(auth()->user(), $this->record) ? parent::getFormActions() : [$this->getCancelFormAction()];
    }

    private function locationAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(LocationRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(LocationRegister::class)->act(
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
                    $this->redirect(LocationMasterResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Location updated')->success()->send();
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

<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Services\HR\PositionRegister;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), PositionResource::recordAction('submit', 'Send for Approval')
            ->fillForm(fn (): array => ['revision' => $this->data['revision']])
            ->after(function (): void {
                $this->record->refresh();
                $this->fillForm();
            }), PositionResource::recordAction('delete', 'Delete')
            ->fillForm(fn (): array => ['revision' => $this->data['revision']])
            ->after(fn () => $this->record->trashed() ? $this->redirect(PositionResource::getUrl('index')) : null)];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(PositionRegister::class)->detail(auth()->user(), $this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PositionRegister::class)->save(auth()->user(), $record->id, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $this->fillForm();
    }

    protected function getFormActions(): array
    {
        return app(PositionRegister::class)->canChange(auth()->user(), $this->record) ? parent::getFormActions() : [$this->getCancelFormAction()];
    }
}

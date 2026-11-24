<?php

namespace App\Filament\Resources\SampleRequests\Pages;

use App\Filament\Resources\SampleRequests\SampleRequestResource;
use App\Models\SampleRequestLine;
use App\Services\Inventory\SampleRequestRegister;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditSampleRequest extends EditRecord
{
    protected static string $resource = SampleRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->requestAction('submit', 'Submit', 'primary'),
            $this->requestAction('approve', 'Approve', 'success')
                ->schema(fn (): array => $this->record->loadMissing('lines.item')->lines->map(
                    fn (SampleRequestLine $line): TextInput => TextInput::make('approved.'.$line->id)
                        ->label(($line->item?->item_name ?? 'Item #'.$line->item_master_id).' (requested '.$line->quantity_requested.')')
                        ->default((float) $line->quantity_approved > 0 ? $line->quantity_approved : $line->quantity_requested)
                        ->numeric()->minValue(0.001)->maxValue($line->quantity_requested)->step(0.001)->rule('decimal:0,3')->required()
                )->all()),
            $this->requestAction('cancel', 'Cancel Request', 'danger'),
            $this->requestAction('delete', 'Delete', 'danger'),
            $this->requestAction('restore', 'Restore', 'success'),
            $this->requestAction('force-delete', 'Permanently Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('lines');
        $data['revision'] = app(SampleRequestRegister::class)->revision($this->record);
        $data['lines'] = $this->record->lines->map(fn (SampleRequestLine $line): array => $line->only([
            'id', 'item_master_id', 'quantity_requested', 'quantity_approved', 'quantity_issued', 'remarks',
        ]))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(SampleRequestRegister::class)->save(auth()->user(), $record->id, $data);
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

    private function requestAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(SampleRequestRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(SampleRequestRegister::class)->act(
                        auth()->user(), [$this->record->id], $action, $this->data['revision'], $data['approved'] ?? []
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();

                    return;
                } catch (HttpException $exception) {
                    $this->notifyConflict($exception);

                    return;
                }

                if (in_array($action, ['delete', 'force-delete'], true)) {
                    $this->redirect(SampleRequestResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Sample request updated')->success()->send();
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

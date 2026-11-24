<?php

namespace App\Filament\Resources\SampleIssues\Pages;

use App\Filament\Resources\SampleIssues\SampleIssueResource;
use App\Models\SampleIssueLine;
use App\Services\Inventory\SampleIssueRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditSampleIssue extends EditRecord
{
    protected static string $resource = SampleIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->issueAction('post', 'Post to Stock', 'success'),
            $this->issueAction('delete', 'Delete', 'danger'),
            $this->issueAction('restore', 'Restore', 'success'),
            $this->issueAction('force-delete', 'Permanently Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('lines');
        $data['revision'] = app(SampleIssueRegister::class)->revision($this->record);
        $data['lines'] = $this->record->lines->map(fn (SampleIssueLine $line): array => $line->only([
            'id', 'sample_request_line_id', 'item_master_id', 'quantity', 'unit_cost', 'remarks',
        ]))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(SampleIssueRegister::class)->save(auth()->user(), $record->id, $data);
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

    private function issueAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(SampleIssueRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(SampleIssueRegister::class)->act(
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
                    $this->redirect(SampleIssueResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Sample issue updated')->success()->send();
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

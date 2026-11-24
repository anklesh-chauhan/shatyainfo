<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Services\HR\EmployeeRegister;
use App\Services\HR\HrChangeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->employeeAction('submit', 'Send for Approval', 'primary'),
            $this->employeeAction('delete', 'Delete', 'danger'),
            $this->employeeAction('restore', 'Restore', 'primary'),
            $this->hrChangeAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['revision'] = app(EmployeeRegister::class)->revision($this->record);
        $data['provision_login'] = false;
        $data['resolved_reporting_manager'] = app(EmployeeRegister::class)->reportingSummary(auth()->user(), $this->record)['reporting_manager'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(EmployeeRegister::class)->save(auth()->user(), $record->id, EmployeeResource::adminInput($data, $record));
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
        return app(EmployeeRegister::class)->canChange(auth()->user(), $this->record) ? parent::getFormActions() : [$this->getCancelFormAction()];
    }

    private function employeeAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(EmployeeRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(EmployeeRegister::class)->act(
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
                    $this->redirect(EmployeeResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('Employee updated')->success()->send();
            });
    }

    private function hrChangeAction(): Action
    {
        return Action::make('request_hr_change')->label('Request HR change')
            ->visible(fn (): bool => app(EmployeeRegister::class)->canRequestChange(auth()->user(), $this->record))
            ->schema([...EmployeeForm::fields('employee', change: true),
                Hidden::make('revision'), Textarea::make('reason')->required()->maxLength(2000)])
            ->fillForm(fn (): array => $this->record->only(array_keys(HrChangeService::rules('employee'))) + ['revision' => app(EmployeeRegister::class)->revision($this->record->fresh())])
            ->action(function (array $data): void {
                app(EmployeeRegister::class)->requestChange(auth()->user(), $this->record->id, 'employee', ['revision' => $data['revision'], 'reason' => $data['reason'], 'values' => Arr::only($data, array_keys(HrChangeService::rules('employee')))]);
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('HR change submitted')->success()->send();
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

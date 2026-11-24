<?php

namespace App\Filament\Resources\SgipDistributions\Pages;

use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Models\ApprovalSetting;
use App\Models\SgipDistribution;
use App\Services\Marketing\SgipRegister;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditSgipDistribution extends EditRecord
{
    protected static string $resource = SgipDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->distributionAction('submit', 'Submit', 'primary'),
            $this->distributionAction('approve', ApprovalSetting::moduleRequiresApproval(SgipDistribution::class) ? 'Approve & Deduct Stock' : 'Post & Deduct Stock', 'success'),
            $this->distributionAction('return', 'Return to Draft', 'warning'),
            $this->distributionAction('delete', 'Delete', 'danger'),
            $this->distributionAction('restore', 'Restore', 'success'),
            $this->distributionAction('force-delete', 'Permanently Delete', 'danger'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['revision'] = app(SgipRegister::class)->revision($this->record);
        $data['items'] = $this->record->items()->get()->map->only(['item_master_id', 'quantity', 'unit_value'])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->record = app(SgipRegister::class)->save(auth()->user(), $record->id, $data);
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

    private function distributionAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (): bool => app(SgipRegister::class)->canAct(auth()->user(), $this->record, $action))
            ->action(function (array $data) use ($action): void {
                try {
                    app(SgipRegister::class)->act(
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
                    $this->redirect(SgipDistributionResource::getUrl('index'));

                    return;
                }
                $this->record->refresh();
                $this->fillForm();
                Notification::make()->title('SGIP distribution updated')->success()->send();
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

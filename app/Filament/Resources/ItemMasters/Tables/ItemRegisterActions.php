<?php

namespace App\Filament\Resources\ItemMasters\Tables;

use App\Models\ItemMaster;
use App\Services\Inventory\ItemRegister;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ItemRegisterActions
{
    public static function record(string $action, string $label, ?int $parentId = null): Action
    {
        return Action::make($action)->label($label)->requiresConfirmation()
            ->visible(fn (ItemMaster $record): bool => app(ItemRegister::class)->canAct(auth()->user(), $record, $action))
            ->schema([Hidden::make('revision')])->fillForm(fn (ItemMaster $record): array => ['revision' => app(ItemRegister::class)->revision($record)])
            ->action(fn (ItemMaster $record, array $data) => self::run(fn () => app(ItemRegister::class)->act(auth()->user(), [$record->id], $action, $data['revision'], parentId: $parentId)));
    }

    public static function bulk(string $action, string $label, ?int $parentId = null): BulkAction
    {
        $ability = match ($action) {
            'delete' => 'DeleteAny', 'restore' => 'RestoreAny', 'force-delete' => 'ForceDeleteAny', default => 'Update'
        };

        return BulkAction::make($action)->label($label)->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => $action !== 'submit' || ItemMaster::moduleHasApprovalFlow())
            ->authorize(fn (): bool => auth()->user()->can($ability.':ItemMaster'))
            ->action(fn (Collection $records) => self::run(fn () => app(ItemRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true, parentId: $parentId)));
    }

    public static function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();

            return;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Items updated')->success()->send();
    }
}

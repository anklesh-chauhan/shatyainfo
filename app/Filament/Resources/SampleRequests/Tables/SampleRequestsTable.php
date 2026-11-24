<?php

namespace App\Filament\Resources\SampleRequests\Tables;

use App\Enums\SampleRequestStatus;
use App\Filament\Resources\SampleIssues\SampleIssueResource;
use App\Models\SampleRequest;
use App\Services\Inventory\SampleRequestRegister;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SampleRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lines'))
            ->columns([
                TextColumn::make('document_number')->label('Request')->searchable()->sortable(),
                TextColumn::make('request_date')->date()->sortable(),
                TextColumn::make('employee.full_name')->label('Representative')->searchable(),
                TextColumn::make('territory.name')->searchable(),
                TextColumn::make('destinationLocation.name')->label('Destination')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SampleRequestStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('createIssue')
                    ->label('Create Issue')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->url(fn (SampleRequest $record): string => SampleIssueResource::getUrl('create', [
                        'sample_request_id' => $record->getKey(),
                    ]))
                    ->visible(fn (SampleRequest $record): bool => app(SampleRequestRegister::class)->canHandoff(auth()->user(), $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulk('delete', 'Delete', 'deleteAny'),
                    self::bulk('force-delete', 'Permanently Delete', 'forceDeleteAny'),
                    self::bulk('restore', 'Restore', 'restoreAny'),
                ]),
            ]);
    }

    private static function bulk(string $action, string $label, string $ability): BulkAction
    {
        return BulkAction::make($action)->label($label)
            ->authorize(fn (): bool => auth()->user()->can($ability, SampleRequest::class))
            ->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($action): void {
                try {
                    app(SampleRequestRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Sample requests updated')->success()->send();
            });
    }
}

<?php

namespace App\Filament\Resources\SampleIssues\Tables;

use App\Enums\SampleIssueStatus;
use App\Models\SampleIssue;
use App\Services\Inventory\SampleIssueRegister;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SampleIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')->label('Issue')->searchable()->sortable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('sampleRequest.document_number')->label('Request')->searchable(),
                TextColumn::make('sampleRequest.employee.full_name')->label('Representative')->searchable(),
                TextColumn::make('fromLocation.name')->label('From')->searchable(),
                TextColumn::make('toLocation.name')->label('To')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SampleIssueStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
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
            ->authorize(fn (): bool => auth()->user()->can($ability, SampleIssue::class))
            ->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($action): void {
                try {
                    app(SampleIssueRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Sample issues updated')->success()->send();
            });
    }
}

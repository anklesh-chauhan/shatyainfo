<?php

namespace App\Filament\Resources\SampleRequests\Tables;

use App\Enums\SampleRequestStatus;
use App\Filament\Resources\SampleIssues\SampleIssueResource;
use App\Models\SampleRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SampleRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->visible(fn (SampleRequest $record): bool => in_array($record->status, [
                        SampleRequestStatus::Approved,
                        SampleRequestStatus::PartiallyIssued,
                    ], true)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}

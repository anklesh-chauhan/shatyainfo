<?php

namespace App\Filament\Resources\SampleIssues\Tables;

use App\Enums\SampleIssueStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

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
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}

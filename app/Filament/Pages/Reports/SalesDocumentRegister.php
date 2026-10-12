<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\SalesDocumentRegisterExporter;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesDocumentRegisterRow;
use App\Services\Reports\SalesDocumentRegisterQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesDocumentRegister extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = SalesDocumentRegisterExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Sales documents';

    protected static ?string $title = 'Sales document register';

    protected static ?int $navigationSort = 18;

    protected function visibilityPermissionKey(): ?string
    {
        return null;
    }

    protected function dateColumn(): ?string
    {
        return 'date';
    }

    protected function userColumn(): ?string
    {
        return 'sales_person_id';
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function getReportQuery(): Builder
    {
        return app(SalesDocumentRegisterQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('document_type')
                ->label('Document')
                ->options([
                    'quote' => 'Quote',
                    'sales_order' => 'Sales order',
                    'sales_invoice' => 'Sales invoice',
                ]),
            SelectFilter::make('status')
                ->label('Status')
                ->options([
                    'draft' => 'Draft',
                    'sent' => 'Sent',
                    'accepted' => 'Accepted',
                    'rejected' => 'Rejected',
                    'canceled' => 'Canceled',
                ]),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('date')
                ->date()
                ->sortable(),
            TextColumn::make('document_type')
                ->label('Type')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'quote' => 'Quote',
                    'sales_order' => 'Sales order',
                    'sales_invoice' => 'Sales invoice',
                    default => (string) $state,
                }),
            TextColumn::make('document_number')
                ->label('Number')
                ->searchable()
                ->sortable(),
            TextColumn::make('accountMaster.name')
                ->label('Account')
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('salesPerson.name')
                ->label('Sales person')
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('status')
                ->badge()
                ->colors([
                    'gray' => 'draft',
                    'info' => 'sent',
                    'success' => 'accepted',
                    'danger' => 'rejected',
                    'warning' => 'canceled',
                ]),
            TextColumn::make('total')
                ->money('INR')
                ->sortable(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(function (SalesDocumentRegisterRow $record): ?string {
                $sourceId = $record->source_id;

                if (blank($sourceId)) {
                    return null;
                }

                return match ($record->document_type) {
                    'quote' => QuoteResource::getUrl('edit', ['record' => $sourceId]),
                    'sales_order' => SalesOrderResource::getUrl('edit', ['record' => $sourceId]),
                    'sales_invoice' => SalesInvoiceResource::getUrl('edit', ['record' => $sourceId]),
                    default => null,
                };
            });
    }
}

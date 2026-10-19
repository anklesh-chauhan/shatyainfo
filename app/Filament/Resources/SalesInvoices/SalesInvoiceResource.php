<?php

namespace App\Filament\Resources\SalesInvoices;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\SalesInvoices\Pages\EditSalesInvoice;
use App\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Models\Organization;
use App\Models\SalesInvoice;
use App\Traits\HasSafeGlobalSearch;
use App\Traits\SalesDocumentResourceTrait;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesInvoiceResource extends BaseResource
{
    use HasSafeGlobalSearch;
    use SalesDocumentResourceTrait;

    protected static ?string $model = SalesInvoice::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::getCommonFormFields(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')
                    ->label('Document No.')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                // Account Master Column
                TextColumn::make('accountMaster.name')
                    ->label('Account Master')
                    ->searchable()
                    ->sortable(),

                // Contact Detail Column
                TextColumn::make('contactDetail.full_name')
                    ->label('Contact')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('INR')
                    ->sortable(),

                TextColumn::make('salesPerson.name')
                    ->label('Sales Person')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_terms')
                    ->label('Payment Terms')
                    ->limit(20),

                TextColumn::make('shipping_method')
                    ->label('Shipping Method')
                    ->limit(20),

                IconColumn::make('rejected_at')
                    ->label('Rejected')
                    ->boolean(),

                IconColumn::make('canceled_at')
                    ->label('Canceled')
                    ->boolean(),

                IconColumn::make('sent_at')
                    ->label('Sent')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('sales_person_id')
                    ->label('Sales Person')
                    ->relationship('salesPerson', 'name')
                    ->searchable(),

                SelectFilter::make('account_master_id')
                    ->label('Account Master')
                    ->relationship('accountMaster', 'name')
                    ->searchable(),

                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('date', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('date', '<=', $data['until']));
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ApprovalAction::make(),

                    Action::make('createDeliveryChallan')
                        ->label('Create Delivery Challan')
                        ->icon('heroicon-o-truck')
                        ->color('warning')
                        ->visible(fn (SalesInvoice $record): bool => $record->hasPendingDelivery() && $record->canProceedAfterApproval())
                        ->url(fn (SalesInvoice $record): string => route('filament.admin.resources.delivery-challans.create', [
                            'sales_invoice_id' => $record->id,
                        ])),

                    Action::make('previewPdf')
                        ->icon('heroicon-o-eye')
                        ->label('Preview PDF')
                        ->modalHeading('PDF Preview')
                        ->modalSubmitActionLabel('Close')
                        ->modalWidth('full')
                        ->modalContent(function ($record) {
                            $url = route('sales-documents.preview', [
                                strtolower(class_basename($record)),
                                $record->id,
                            ]);

                            return view('components.pdf-preview', [
                                'url' => $url,
                                'organization' => Organization::first(),
                            ]);
                        }),

                    Action::make('downloadPdf')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->label('Download PDF')
                        ->url(fn ($record) => route('sales-documents.download', [
                            strtolower(class_basename($record)), $record->id,
                        ])),
                ]),

            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
            'create' => CreateSalesInvoice::route('/create'),
            'edit' => EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}

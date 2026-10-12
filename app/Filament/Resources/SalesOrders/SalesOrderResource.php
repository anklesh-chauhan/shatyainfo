<?php

namespace App\Filament\Resources\SalesOrders;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Helpers\SalesDocumentHelper;
use App\Models\Organization;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
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
use Illuminate\Database\Eloquent\Builder;

class SalesOrderResource extends BaseResource
{
    use HasSafeGlobalSearch;
    use SalesDocumentResourceTrait;

    protected static ?string $model = SalesOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Sales Orders';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->applyVisibility(static::getModelLabel());
    }

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

                TextColumn::make('lead.reference_code')
                    ->label('Lead')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('salesPerson.name')
                    ->label('Sales Person')
                    ->searchable()
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

                TextColumn::make('currency')
                    ->label('Currency')
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

                    Action::make('createInvoice')
                        ->label('Create Invoice')
                        ->icon('heroicon-o-document-text')
                        ->requiresConfirmation()
                        ->color('success')
                        ->action(function (SalesOrder $record, $livewire) {
                            $invoice = SalesDocumentHelper::createFrom($record, SalesInvoice::class);

                            return redirect(route('filament.admin.resources.sales-invoices.edit', $invoice));
                        }),

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
            'index' => ListSalesOrders::route('/'),
            'create' => CreateSalesOrder::route('/create'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
        ];
    }
}

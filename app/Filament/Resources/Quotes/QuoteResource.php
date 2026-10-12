<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Helpers\SalesDocumentHelper;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Traits\HasSafeGlobalSearch;
use App\Traits\SalesDocumentResourceTrait;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class QuoteResource extends BaseResource
{
    use HasSafeGlobalSearch;
    use SalesDocumentResourceTrait;

    protected static ?string $model = Quote::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    protected static function resolveModelClass(): string
    {
        return method_exists(static::class, 'getModel') ? static::getModel() : Quote::class;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Common fields for all sales documents
                ...self::getCommonFormFields(),

                DatePicker::make('expiration_date'),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                        'canceled' => 'Canceled',
                    ])
                    ->default('draft') // Set the default value
                    ->required()
                    ->label('Status'),
                DatePicker::make('accepted_at'),

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

                TextColumn::make('accountMaster.name')
                    ->label('Company')
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

                TextColumn::make('status')
                    ->label('Status')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('sent_at')
                    ->label('Sent')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ApprovalAction::make(),
                    Action::make('createSalesOrder')
                        ->label('Create Sales Order')
                        ->icon('heroicon-o-document-plus')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Quote $record) {
                            $salesOrder = SalesDocumentHelper::createFrom($record, SalesOrder::class);

                            return redirect()->route('filament.admin.resources.sales-orders.edit', $salesOrder);
                        }),

                    Action::make('createSalesInvoice')
                        ->label('Create Sales Invoice')
                        ->icon('heroicon-o-document-plus')
                        ->requiresConfirmation()
                        ->color('warning')
                        ->action(function (Quote $record) {
                            $salesInvoice = SalesDocumentHelper::createFrom($record, SalesInvoice::class);

                            return redirect()->route('filament.admin.resources.sales-invoices.edit', $salesInvoice);
                        }),

                    Action::make('previewPdf')
                        ->icon('heroicon-o-eye')
                        ->label('Preview PDF')
                        ->modalHeading('PDF Preview')
                        ->modalSubmitActionLabel('Close')
                        ->modalWidth('full')
                        ->modalContent(fn ($record) => view('components.pdf-preview', [
                            'url' => route('sales-documents.preview', [
                                strtolower(class_basename($record)),
                                $record->id,
                            ]),
                            'organization' => Organization::first(),
                        ])),

                    Action::make('downloadPdf')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->label('Download PDF')
                        ->url(fn ($record) => route('sales-documents.download', [
                            strtolower(class_basename($record)), $record->id,
                        ])),

                ]),
            ], position: RecordActionsPosition::BeforeColumns)
            ->filters([
                // 📅 Date Range Filter
                Filter::make('date')
                    ->label('Document Date Range')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('date', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('date', '<=', $data['until']));
                    }),

                // 🏢 Company Filter
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),

                // 👤 Contact Person Filter
                SelectFilter::make('contact_detail_id')
                    ->label('Contact')
                    ->relationship('contactDetail', 'first_name')
                    ->searchable()
                    ->preload(),

                // 💼 Sales Person Filter
                SelectFilter::make('sales_person_id')
                    ->label('Sales Person')
                    ->relationship('salesPerson', 'name')
                    ->searchable()
                    ->preload(),

                // 📌 Status Filter
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                // ✅ Sent Boolean Filter
                Filter::make('sent_at')
                    ->label('Sent')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('sent_at'))
                    ->toggle(),

                // 💰 Total Amount Range Filter
                Filter::make('total')
                    ->label('Total Amount Range')
                    ->schema([
                        TextInput::make('min')->numeric()->placeholder('Min'),
                        TextInput::make('max')->numeric()->placeholder('Max'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['min'], fn ($q) => $q->where('total', '>=', $data['min']))
                            ->when($data['max'], fn ($q) => $q->where('total', '<=', $data['max']));
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),

                    // SEND FOR APPROVAL
                    BulkAction::make('sendForApproval')
                        ->label('Send for Approval')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('primary')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                try {
                                    if ($record->canStartApproval()) {
                                        $record->startApprovalFromRules();
                                        $count++;
                                    }
                                } catch (\Exception $e) {
                                    // skip or collect errors
                                }
                            }
                            Notification::make()
                                ->title("Sent approval for {$count} records")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('QuoteResource handleRecordCreation called');

        return $this->handleRecordCreation($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('QuoteResource handleRecordUpdate called');

        return $this->handleRecordUpdate($record, $data);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}

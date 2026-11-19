<?php

namespace App\Traits;

use App\Models\Quote;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use App\Models\NumberSeries;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Fieldset;
use App\Traits\ContactDetailsTrait;
use App\Traits\CompanyDetailsTrait;
use App\Traits\AddressDetailsTrait;
use App\Traits\ItemMasterTrait;
use App\Traits\AccountMasterDetailsTrait;
use App\Traits\SalesDocumentPreferenceTrait;
use Filament\Forms\Components\RichEditor;
use App\Models\ItemMaster;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use App\Models\Tax;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Support\Enums\VerticalAlignment;
use App\Models\SalesDocumentPreference;
use App\Models\Address;
use App\Models\Organization;
use App\Models\TaxDetail;
use Illuminate\Support\Collection;
use App\Models\TermsAndConditionsMaster;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Infolists\Components\TextEntry;

trait SalesDocumentResourceTrait
{
    use ContactDetailsTrait;
    use CompanyDetailsTrait;
    use AddressDetailsTrait;
    use ItemMasterTrait;
    use AccountMasterDetailsTrait;
    use SalesDocumentPreferenceTrait;

    protected static function resolveModelClass(): string
    {
        return method_exists(static::class, 'getModel') ? static::getModel() : Quote::class;
    }

    protected static function hasRelation(string $relation): bool
    {
        $modelClass = static::resolveModelClass();

        $model = is_string($modelClass) ? new $modelClass() : $modelClass;

        return method_exists($model, $relation);
    }

    public static function getCommonFormFields(): array
    {

        $companyAccountFields = self::getAccountMasterDetailsTraitField();

        // Get the record from the route
        $record = request()->route('record');
        $modelClass = static::resolveModelClass();

        // If record is a string (e.g., ID), fetch the actual model instance
        if (is_string($record)) {
            $record = $modelClass::find($record);
        }
        // Determine discount mode: prioritize record's discount_mode, fallback to preference

        $recordDiscountMode = optional($record)->discount_mode;

        // ⚠️ CHANGE: Get discount mode from form state if available, otherwise from record or preference
        // Define the callable to get the discount mode value
        $discountModeCallable = fn(callable $get) => $get('discount_mode')
            ?? optional($record)->discount_mode
            ?? (SalesDocumentPreference::first()?->discount_level ?? 'none');

        // Now, define the visibility booleans using the callable's result
        $showLineItemDiscount = fn(callable $get) => in_array($discountModeCallable($get), ['line_item', 'both']);
        $showTransactionDiscount = fn(callable $get) => in_array($discountModeCallable($get), ['transaction', 'both']);


        // $showLineItemDiscount = SalesDocumentPreference::first()?->discount_level === 'line_item';

        return [
            Grid::make(5)
                ->schema([
                    TextInput::make('document_number')
                        ->label('Document Number')
                        ->default(fn () => NumberSeries::getNextNumber(static::resolveModelClass()))
                        ->disabled()
                        ->dehydrated(true),
                    DatePicker::make('date')
                        ->label('Date')
                        ->default(now()->toDateString())
                        ->required(),

                    Select::make('sales_person_id')
                        ->label('Sales Person')
                        ->options(function () {
                            return User::all()->pluck('name', 'id')->toArray();
                        })
                        ->searchable()
                        ->preload()
                        ->placeholder('Select a sales person...')
                        ->required()
                        ->default(Auth::id()),

                    Select::make('quote_ids')
                        ->label('Related Quotes')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->when(static::hasRelation('quotes'), function ($field) {
                            return $field->relationship('quotes', 'document_number');
                        })
                        ->hidden(fn () => static::resolveModelClass() === \App\Models\Quote::class),

                    Select::make('sales_order_ids')
                        ->label('Related Sales Orders')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->when(static::hasRelation('salesOrders'), function ($field) {
                            return $field->relationship('salesOrders', 'document_number');
                        })
                        ->hidden(fn () => static::resolveModelClass() === \App\Models\SalesOrder::class),

                    Select::make('sales_invoice_ids')
                        ->label('Related Sales Invoices')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->when(static::hasRelation('salesInvoices'), function ($field) {
                            return $field->relationship('salesInvoices', 'document_number');
                        })
                        ->hidden(fn () => static::resolveModelClass() === \App\Models\SalesInvoice::class),


                    Hidden::make('discount_mode')
                        ->default(fn () =>
                            optional($record)->discount_mode
                            ?? SalesDocumentPreference::first()?->discount_level
                            ?? 'none'
                        ),

                ])->columnSpanFull(),

                ...$companyAccountFields,

                ...self::getContactDetailsTraitField(),
                ...self::getAddressDetailsTraitField(
                    fieldName: 'billing_address_id',
                    label: 'Billing Address',
                    relationshipName: 'billingAddress'
                ),
                Checkbox::make('has_shipping_address')
                    ->label('Add Shipping Address')
                    ->live()
                    ->default(false),
                Group::make()
                    ->schema(
                        self::getAddressDetailsTraitField(
                            fieldName: 'shipping_address_id',
                            label: 'Shipping Address',
                            relationshipName: 'shippingAddress'
                        )
                    )
                    ->hidden(fn (callable $get) => !$get('has_shipping_address') && !$get('shipping_address_id')),

            Section::make() // Two-column layout
            ->schema([
                Repeater::make('items')
                    ->relationship('items')
                    ->columnSpanFull()
                    ->label(false)
                    ->extraAttributes([
                        'class' => 'invoice-items-repeater',
                    ])
                    ->table(
                        fn (callable $get) => collect([
                            TableColumn::make('Item                                                                    ')
                                ->width('350px'),
                            TableColumn::make('Description                                                            ')->width('450px'),
                            TableColumn::make('Quantity           ')->width('100px'),
                            TableColumn::make('HSN/SAC'),
                            TableColumn::make('Unit Price       ')->width('100px'),
                            TableColumn::make('Line Gross Amt')->width('100px'),

                            // 🚀 Correctly execute the callable here with the $get from the closure
                            $showLineItemDiscount($get)
                                ? TableColumn::make('Disc %        ')->width('100px')
                                : null,

                            TableColumn::make('Tax Rate %              ')->width('100px'),
                            TableColumn::make('Amount                      ')->width('100px'),
                            TableColumn::make('Taxable Amt        ')->width('100px'),
                            TableColumn::make(' ')->width('10px'),
                        ])->filter()->all()
                    )
                    ->relationship('items')
                    ->schema(
                        fn (callable $get) => collect([
                        Select::make('item_master_id')
                            ->label(false)
                            ->relationship('itemMaster', 'item_name')
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live()
                            ->options(function () {
                                return \App\Models\ItemMaster::with('parent')
                                    ->orderByRaw('COALESCE(parent_id, id), parent_id, item_name')
                                    ->get()
                                    ->mapWithKeys(function ($item) {
                                        $label = $item->parent
                                            ? "{$item->parent->item_name} – {$item->variant_name}"
                                            : $item->item_name;

                                        return [$item->id => $label];
                                    })
                                    ->toArray();
                            })
                            ->getSearchResultsUsing(function (string $search): array {
                                return \App\Models\ItemMaster::with('parent')
                                    ->whereLike('item_name', "%{$search}%")
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(function ($item) {
                                        $label = $item->parent
                                            ? "{$item->parent->item_name} – {$item->variant_name}"
                                            : $item->item_name;

                                        return [$item->id => $label];
                                    })
                                    ->toArray();
                            })
                            ->getOptionLabelUsing(function ($value) {
                                $item = \App\Models\ItemMaster::with('parent')->find($value);
                                if (!$item) {
                                    return null;
                                }

                                return $item->parent
                                    ? "{$item->parent->item_name} – {$item->variant_name}"
                                    : $item->item_name;
                            })
                            ->createOptionForm([
                                ...self::getItemMasterTraitField()
                            ])
                            ->createOptionAction(function (Action $action) {
                                return $action
                                    ->modalHeading('Create New Item')
                                    ->modalSubmitActionLabel('Create')
                                    ->closeModalByClickingAway(false)
                                    ->mutateDataUsing(function (array $data) {
                                        $data['item_code'] = $data['item_code'] ?? NumberSeries::getNextNumber(ItemMaster::class);
                                        return $data;
                                    });
                            }) // No visible() condition, always shown
                            ->editOptionForm([
                                ...self::getItemMasterTraitField() // Define the edit form fields
                            ])
                            ->editOptionAction(function (Action $action) {
                                return $action
                                    ->modalHeading('Edit Item')
                                    ->modalSubmitActionLabel('Save')
                                    ->closeModalByClickingAway(false)
                                    ->visible(fn ($get) => !empty($get('item_master_id'))); // Show only if item_master_id is set
                            })
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                if (!$state) {
                                    $set('description', '');
                                    $set('hsn_sac', '');
                                    $set('unit_price', 0);
                                    $set('tax_rate', 0);
                                    $set('discount', 0);
                                    return;
                                }

                                $item = \App\Models\ItemMaster::with('taxes')->find($state);
                                $accountMasterId = $get('../../account_master_id');

                                if (!$item) {
                                    $set('description', '');
                                    $set('hsn_sac', '');
                                    $set('unit_price', 0);
                                    $set('tax_rate', 0);
                                    $set('discount', 0);
                                    return;
                                }

                                // ✅ Base item details
                                $set('description', $item->description ?? '');
                                $set('hsn_sac', $item->hsn_code ?? '');

                                // ✅ Default values (fallback from ItemMaster)
                                $defaultPrice = $item->selling_price ?? 0;
                                $defaultDiscount = $item->discount ?? 0;

                                // ✅ Calculate total tax rate from related taxes
                                $totalTaxRate = $item->taxes->sum('total_rate');
                                $set('tax_rate', number_format($totalTaxRate, 2, '.', ''));

                                // ✅ Try to fetch account-specific price
                                $accountPrice = null;
                                if ($accountMasterId) {
                                    $accountPrice = \App\Models\CustomerPrice::where('customer_id', $accountMasterId)
                                        ->where('item_master_id', $item->id)
                                        ->first();
                                }

                                // ✅ Use account-specific price if available, else fallback to item defaults
                                $set('unit_price', $accountPrice->price ?? $defaultPrice);
                                $set('discount', $accountPrice->discount ?? $defaultDiscount);
                            }),

                            Textarea::make('description')
                                ->label(false)
                                ->maxWidth(450)
                                ->rows(1)
                                ->placeholder('Enter item description...')
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[550px]'])
                                ->columnSpanFull(),

                            TextInput::make('quantity')
                                ->label(false)
                                ->numeric()
                                ->default(0)
                                // ->required()
                                ->live()
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    self::updateItemAmount($set, $get);
                                    self::updateTotals($set, $get);
                                }),

                            TextInput::make('hsn_sac')
                                ->label('Hsn/Sac')
                                ->live()
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->placeholder('HSN/SAC'),

                            TextInput::make('unit_price')
                                ->label(false)
                                ->numeric()
                                ->default(0)
                                ->required()
                                ->live(onBlur: true)
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    self::updateItemAmount($set, $get);
                                    self::updateTotals($set, $get);
                                }),

                            TextInput::make('price')
                                ->label(false)
                                ->numeric()
                                ->default(0)
                                ->required()
                                ->live(onBlur: true)
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    self::updateItemAmount($set, $get);
                                    self::updateTotals($set, $get);
                                }),

                            $showLineItemDiscount($get)
                                ? TextInput::make('discount')
                                    ->label(false)
                                    ->numeric()
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        self::updateItemAmount($set, $get);
                                        self::updateTotals($set, $get);
                                    })
                                : null,

                            Select::make('tax_rate')
                                ->label(false)
                                ->options(function (callable $get) {
                                    $itemId = $get('item_master_id');

                                    if (!$itemId) {
                                        return [];
                                    }

                                    $item = ItemMaster::with('taxes')->find($itemId);

                                    if ($item && $item->taxes->isNotEmpty()) {
                                        return $item->taxes->mapWithKeys(function ($tax) {
                                            return [
                                                number_format($tax->total_rate, 2, '.', '') => number_format($tax->total_rate, 2) . '%',
                                            ];
                                        })->toArray();
                                    }

                                    // Fallback: list all taxes if no item taxes are found
                                    return Tax::active()
                                        ->get()
                                        ->mapWithKeys(function ($tax) {
                                            return [
                                                number_format($tax->total_rate, 2, '.', '') => number_format($tax->total_rate, 2) . '%',
                                            ];
                                        })->toArray();
                                })
                                ->searchable()
                                ->preload()
                                ->live()
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->placeholder('Tax Rate')
                                ->required(),

                            TextInput::make('amount')
                                ->label(false)
                                ->readOnly()
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->dehydrated(true)
                                ->inputMode('decimal') // Enforce decimal input behavior
                                ->step('0.01')
                                ->extraInputAttributes([
                                    'min' => 0,
                                    'step' => '0.01', // Reinforce 2 decimal places in the HTML input
                                ])
                                ->formatStateUsing(function ($state): string {
                                    // Convert state to float to handle null, empty, or integer values
                                    $value = (float) ($state ?? 0);
                                    // Always format to 2 decimal places
                                    return number_format($value, 2, '.', '');
                                }),
                            TextInput::make('final_taxable_amount')
                                ->label(false)
                                ->readOnly()
                                ->extraFieldWrapperAttributes(['class' => 'min-w-[100px]'])
                                ->dehydrated(true)
                                ->inputMode('decimal') // Enforce decimal input behavior
                                ->step('0.01')
                                ->extraInputAttributes([
                                    'min' => 0,
                                    'step' => '0.01', // Reinforce 2 decimal places in the HTML input
                                ])
                                ->formatStateUsing(function ($state): string {
                                    // Convert state to float to handle null, empty, or integer values
                                    $value = (float) ($state ?? 0);
                                    // Always format to 2 decimal places
                                    return number_format($value, 2, '.', '');
                                }),
                        ])->filter()->all() // removes null entries safely
                    )
                    ->afterStateHydrated(function (callable $set, callable $get) {
                        self::updateTotals($set, $get);
                    })
                    ->afterStateUpdated(function (callable $set, callable $get, $record) {
                        self::updateTotals($set, $get);

                        $items = $get('items') ?? [];
                        foreach ($items as $key => $item) {
                            $path = "items.{$key}";
                            self::updateItemAmount(
                                fn ($field, $value) => $set("{$path}.{$field}", $value),
                                fn ($field) => $get("{$path}.{$field}")
                            );
                        }
                    })
                    ->columnSpanFull()
                    ->addActionLabel('Add Item'),
            ])->columnSpanFull(),

            Section::make()
                ->schema([
                    // 1. Replace Grid with Tabs
                    Tabs::make('Totals and Calculations')
                        ->tabs([
                            // 2. Tab for User Input/Summary (The fields you showed)
                            Tabs\Tab::make('Summary')
                                ->schema([
                                    Grid::make(7) // You can keep the grid inside the tab
                                        ->schema([
                                            // The first set of fields goes here (Discount Type, Value, Discount Total, Subtotal, Total)
                                            Select::make('discount_type')
                                                ->label('Trn Disc Type')
                                                ->options([
                                                    'percentage' => '%',
                                                    'amount'     => '₹',
                                                ])
                                                ->native(false)
                                                ->default(fn () => $record && $record->discount_type ? $record->discount_type : 'percentage')
                                                ->live() // make the select reactive
                                                ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                                    self::updateTotals($set, $get)
                                                )
                                                ->hidden(fn (callable $get) => !$showTransactionDiscount($get)),

                                            TextInput::make('discount_value')
                                                ->label('Trn Disc Value')
                                                ->numeric()
                                                ->default(fn () => $record && is_numeric($record->discount_value) ? $record->discount_value : 0)
                                                    ->minValue(0)
                                                    ->maxValue(fn (callable $get) => $get('discount_type') === 'percentage' ? 100 : 99999999)
                                                ->suffix(fn (callable $get) => $get('discount_type') === 'percentage' ? '%' : '₹')
                                                ->live() // make the select reactive
                                                ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                                    self::updateTotals($set, $get)
                                                )
                                                ->hidden(fn (callable $get) => !$showTransactionDiscount($get)),

                                                // Show computed discount amount (read-only)
                                            TextInput::make('total_discount_amount') // 🚀 CHANGED field name
                                                ->label('Discount Total') // 🚀 CHANGED label
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),

                                            TextInput::make('subtotal')
                                                ->label('Subtotal')
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),

                                            TextInput::make('cgst')
                                                ->label('CGST')
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                                ->visible(fn (callable $get) => self::shouldShowCgstSgst($get)),

                                            TextInput::make('sgst')
                                                ->label('SGST')
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                                ->visible(fn (callable $get) => self::shouldShowCgstSgst($get)),

                                            TextInput::make('igst')
                                                ->label('IGST')
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                                ->visible(fn (callable $get) => !self::shouldShowCgstSgst($get)),

                                            TextInput::make('total')
                                                ->label('Total')
                                                ->reactive()
                                                ->readOnly()
                                                ->extraInputAttributes(['class' => 'text-right'])
                                                ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),
                                        ]),
                                ]),

                            // 3. Tab for Full Calculations/Tax Details
                            Tabs\Tab::make('Detailed Calculations')
                                ->schema([
                                    TextInput::make('gross_total')
                                        ->label('Total Before Discount')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),
                                    TextInput::make('total_line_item_discount')
                                        ->label('Total Line Item Discount (-)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),
                                    TextInput::make('transaction_discount')
                                        ->label('Transaction Discount (-)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),

                                    TextInput::make('subtotal')
                                        ->label('Subtotal After Discounts')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),

                                    TextInput::make('tax')
                                        ->label('Total Tax Amount (+)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),
                                    TextInput::make('round_off')
                                        ->label('Round Off')
                                        ->inlineLabel()
                                        ->default(0)
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->hidden(fn ($get) => ($get('round_off') ?? 0) <= 0)
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),
                                    TextInput::make('shipping_cost')
                                        ->label('Shipping Charges (+)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->reactive()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->hidden(fn ($get) => ($get('shipping_cost') ?? 0) <= 0)
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                        ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                            self::updateTotals($set, $get)
                                        ),

                                    TextInput::make('packing_forwarding')
                                        ->label('Packing & Forwarding (+)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->hidden(fn ($get) => ($get('packing_forwarding') ?? 0) <= 0)
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                        ->reactive()
                                        ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                            self::updateTotals($set, $get)
                                        ),

                                    TextInput::make('insurance_charges')
                                        ->label('Insurance Charges (+)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->hidden(fn ($get) => ($get('insurance_charges') ?? 0) <= 0)
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                        ->reactive()
                                        ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                            self::updateTotals($set, $get)
                                        ),

                                    TextInput::make('other_charges')
                                        ->label('Other Charges (+)')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->hidden(fn ($get) => ($get('other_charges') ?? 0) <= 0)
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state)
                                        ->reactive()
                                        ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                            self::updateTotals($set, $get)
                                        ),

                                    TextInput::make('total')
                                        ->label('Final Total')
                                        ->inlineLabel()
                                        ->readOnly()
                                        ->extraInputAttributes(['class' => 'text-right'])
                                        ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, '.', '') : $state),

                                ]),
                        ])->columnSpanFull(),

                    // Keep all hidden fields here, outside of the Tabs, for simplicity
                    Hidden::make('discount_mode'),
                    Hidden::make('gross_total'),
                    Hidden::make('tax'),
                    Hidden::make('total_line_item_discount'),
                    Hidden::make('transaction_discount'),
                ])
                ->columnSpanFull(),

                Grid::make(4)
                    ->schema([
                        TextInput::make('shipping_cost')
                            ->maxLength(255)
                            ->numeric()
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                self::updateTotals($set, $get)
                            ),
                        TextInput::make('packing_forwarding')
                            ->label('Packing & Forwarding')
                            ->numeric()
                            ->live()
                            ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                self::updateTotals($set, $get)
                            ),
                        TextInput::make('insurance_charges')
                            ->label('Insurance Charges')
                            ->numeric()
                            ->live()
                            ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                self::updateTotals($set, $get)
                            ),
                        TextInput::make('other_charges')
                            ->label('Other Charges')
                            ->numeric()
                            ->live()
                            ->afterStateUpdated(fn ($state, callable $set, callable $get) =>
                                self::updateTotals($set, $get)
                            ),
                ])
                ->columnSpanFull(),

            Grid::make(4)
                ->schema([
                    Select::make('payment_term_id')
                        ->label('Payment Terms')
                        ->relationship('paymentTerm', 'name')
                        ->preload()
                        ->searchable()
                        ->placeholder('Select payment terms...'),
                    Select::make('payment_method_id')
                        ->label('Payment Method')
                        ->relationship('paymentMethod', 'name')
                        ->preload()
                        ->searchable()
                        ->placeholder('Select payment method...'),
                    Select::make('shipping_method_id')
                        ->label('Shipping Method')
                        ->relationship('shippingMethod', 'name')
                        ->preload()
                        ->searchable()
                        ->placeholder('Select shipping method...'),
                    Select::make('lead_id')
                        ->label('Lead')
                        ->relationship('lead', 'reference_code')
                        ->preload()
                        ->searchable(),

                ])->columnSpanFull(),

            Grid::make(4)
                ->schema([
                    DatePicker::make('rejected_at'),
                    DatePicker::make('canceled_at'),
                    DatePicker::make('sent_at'),
                    TextInput::make('currency')
                        ->required()
                        ->maxLength(3)
                        ->default('INR'),
                ])->columnSpanFull(),

            RichEditor::make('terms_and_conditions')
                ->label('Terms and Conditions')
                ->toolbarButtons([
                    'bold', 'italic', 'underline',
                    'orderedList', 'bulletList',
                    'h2', 'h3', 'undo', 'redo',
                ])
                ->maxLength(65535)
                ->columnSpanFull()
                ->default(function (?Model $record) {
                    // Determine document type
                    $documentType = strtolower((new \ReflectionClass(static::resolveModelClass()))->getShortName());

                    // Fetch default terms from master table
                    $defaultTerms = \App\Models\TermsAndConditionsMaster::where('document_type', $documentType)
                        ->where('is_default', true)
                        ->orderBy('id')
                        ->get();

                    // Combine all default contents into a single string for RichEditor
                    $content = $defaultTerms->map(fn($term) => (string) $term->content)->implode("\n\n");

                    return $content;
                })
                ->afterStateHydrated(function ($component, $state, $record) {
                    // If a polymorphic record exists, show its content
                    if ($record && $record->termsAndCondition) {
                        $component->state($record->termsAndCondition->content);
                    }
                })
                ->saveRelationshipsUsing(function ($state, $record) {
                    // Create or update single polymorphic record
                    $record->termsAndCondition()->updateOrCreate(
                        [], // match by model_id/model_type
                        ['content' => $state]
                    );
                }),

                Textarea::make('description')
                    ->label('Internal Notes')
                    ->columnSpanFull(),
        ];
    }

    public static function getCommonTableColumns(): array
    {
        return [
            TextColumn::make('document_number')
                ->label('Document Number'),
            TextColumn::make('lead.lead_code')
                ->label('Lead Code'),
            TextColumn::make('contactDetail.first_name')
                ->label('Contact Name'),
            TextColumn::make('company.name')
                ->label('Company Name'),
            TextColumn::make('date')
                ->label('Date')
                ->date(),
            TextColumn::make('subtotal')
                ->label('Subtotal')
                ->money('USD'),
            TextColumn::make('tax')
                ->label('Tax')
                ->money('USD'),
            TextColumn::make('total')
                ->label('Total')
                ->money('USD'),
            TextColumn::make('status')
                ->label('Status'),
        ];
    }

    private static function shouldShowCgstSgst(callable $get): bool
    {
        $billingAddressId = $get('billing_address_id');
        if (!$billingAddressId) {
            return false;
        }

        $billingAddress = Address::query()->withoutGlobalScopes()->find($billingAddressId);
        $organization = Organization::query()->withoutGlobalScopes()->first();

        if (!$billingAddress || !$organization) {
            return false;
        }

        $organizationAddress = $organization
            ->addresses()
            ->withoutGlobalScopes()
            ->first();
        $organizationState = $organizationAddress?->state;
        $billingState = $billingAddress->state;

        return $billingState && $organizationState &&
            strtolower(trim($billingState)) === strtolower(trim($organizationState));
    }


    protected function getDiscountLevel(): string
    {
        return SalesDocumentPreference::first()?->discount_level ?? 'none';
    }

    private static function updateItemAmount(callable $set, callable $get): void
    {
        $quantity = floatval($get('quantity') ?? 1);
        $unit_price = floatval($get('unit_price') ?? 0);
        $discount = floatval($get('discount') ?? 0);

        $lineGross = $quantity * $unit_price;
        $discountAmount = ($quantity * $unit_price) * ($discount / 100);
        $amount = ($quantity * $unit_price) - $discountAmount;

        $set('price', number_format($lineGross, 2, '.', ''));
        // $set('amount', number_format($amount, 2, '.', ''));

    }

    private static function updateTotals(callable $set, callable $get): void
    {
        $items = $get('items') ?? [];

        $shipping = floatval($get('shipping_cost') ?? 0);
        $packing  = floatval($get('packing_forwarding') ?? 0);
        $insurance = floatval($get('insurance_charges') ?? 0);
        $other = floatval($get('other_charges') ?? 0);

        // Get the record from the route
        $record = request()->route('record');
        $modelClass = static::resolveModelClass();
        if (is_string($record)) {
            $record = $modelClass::find($record);
        }

        // Determine discount mode: prioritize record's discount_mode, fallback to preference
        $recordDiscountMode = optional($record)->discount_mode;
        $discountLevel = $get('discount_mode')
            ?? optional($record)->discount_mode
            ?? (SalesDocumentPreference::first()?->discount_level ?? 'none');

        $originalSubtotal = 0;
        $discountedSubtotal = 0;
        $cgstTotal = 0;
        $sgstTotal = 0;
        $igstTotal = 0;
        $transactionDiscountAmount = 0;
        $totalLineItemDiscount = 0; // 🚀 NEW: total line item discount

        $useCgstSgst = self::shouldShowCgstSgst($get);
        $lineData = [];

        // --- First loop: calculate gross + line-level discounts ---
        foreach ($items as $key => $item) {
            $path = "items.{$key}";
            $quantity = floatval($get("{$path}.quantity") ?? 1);
            $unit_price = floatval($get("{$path}.unit_price") ?? 0);
            $discount = floatval($get("{$path}.discount") ?? 0);

            $lineSubtotal = $quantity * $unit_price;
            $originalSubtotal += $lineSubtotal;

            $lineDiscountAmount = 0;
            if ($discountLevel === 'line_item' || $discountLevel === 'both') {
                $lineDiscountAmount = $lineSubtotal * ($discount / 100);
                $totalLineItemDiscount += $lineDiscountAmount; // 🚀 NEW: sum line discounts
            }

            $lineAmount = $lineSubtotal - $lineDiscountAmount;
            $discountedSubtotal += $lineAmount;

            $set("{$path}.amount", number_format($lineAmount, 2, '.', ''));

            $lineData[$key] = [
                'gross' => $lineSubtotal,   // Keep gross for proportion allocation
                'amount' => $lineAmount,    // Net after line discount
                'tax_rate' => floatval($get("{$path}.tax_rate") ?? 0),
                'item_master_id' => $get("{$path}.item_master_id"),
            ];
        }

        // --- Transaction-level discount ---
        if ($discountLevel === 'transaction' || $discountLevel === 'both') {
            // Use record values in edit mode, otherwise form values
            $discountTypeRaw = $record && $record->discount_type ? $record->discount_type : ($get('discount_type') ?? 'percentage');
            $discountValue = $record && is_numeric($record->discount_value) ? floatval($record->discount_value) : floatval($get('discount_value') ?? 0);
            $discountType = strtolower(trim($discountTypeRaw));

            // Calculate transaction discount
            if ($discountType === 'percentage' || $discountType === '%') {
                $transactionDiscountAmount = $discountedSubtotal * ($discountValue / 100);
            } else {
                $transactionDiscountAmount = $discountValue;
            }

            // In edit mode, use stored transaction_discount if available
            if ($record && is_numeric($record->transaction_discount) && $discountLevel === 'both') {
                $transactionDiscountAmount = floatval($record->transaction_discount);
            }

            $discountedSubtotal = max(0, $discountedSubtotal - $transactionDiscountAmount);
        }

        // --- Calculate Taxes on final discounted amount (after both discounts if applicable) ---
        foreach ($lineData as $key => $data) {
            $path = "items.{$key}";
            $proportion = $originalSubtotal > 0 ? ($data['gross'] / $originalSubtotal) : 0;
            $adjustedAmount = $data['amount']; // Start with line-level discounted amount

            if ($discountLevel === 'transaction' || $discountLevel === 'both') {
                $allocatedTransactionDiscount = $transactionDiscountAmount * $proportion;
                $adjustedAmount = max(0, $adjustedAmount - $allocatedTransactionDiscount);
            }

            $set("{$path}.final_taxable_amount", number_format($adjustedAmount, 2, '.', ''));

            if ($data['item_master_id'] && $data['tax_rate']) {
                $item = ItemMaster::with('taxes.components')->find($data['item_master_id']);
                $tax = $item?->taxes?->firstWhere('total_rate', $data['tax_rate']);
                if ($tax) {
                    foreach ($tax->components as $component) {
                        if ($useCgstSgst) {
                            if ($component->type === 'CGST') {
                                $cgstTotal += $adjustedAmount * ($component->rate / 100);
                            }
                            if ($component->type === 'SGST') {
                                $sgstTotal += $adjustedAmount * ($component->rate / 100);
                            }
                        } else {
                            if ($component->type === 'IGST') {
                                $igstTotal += $adjustedAmount * ($component->rate / 100);
                            }
                        }
                    }
                }
            }
        }

        $tax = $cgstTotal + $sgstTotal + $igstTotal;
        $total = $discountedSubtotal + $tax + $shipping + $packing + $insurance + $other;

        // --- Set values in form state ---
        $set('gross_total', number_format($originalSubtotal, 2, '.', ''));
        $set('subtotal', number_format($discountedSubtotal, 2, '.', ''));

        // 🚀 NEW: Set the total line item discount and the total of all discounts
        $set('total_line_item_discount', number_format($totalLineItemDiscount, 2, '.', ''));
        $set('transaction_discount', number_format($transactionDiscountAmount, 2, '.', ''));
        $totalDiscount = $totalLineItemDiscount + $transactionDiscountAmount;
        $set('total_discount_amount', number_format($totalDiscount, 2, '.', ''));

        $set('cgst', number_format($cgstTotal, 2, '.', ''));
        $set('sgst', number_format($sgstTotal, 2, '.', ''));
        $set('igst', number_format($igstTotal, 2, '.', ''));
        $set('tax', number_format($tax, 2, '.', ''));
        $set('total', number_format($total, 2, '.', ''));
    }

    public function afterCreate(): void
    {
        $this->saveTaxDetailsFromModel();
    }

    public function afterSave(): void
    {
        $this->saveTaxDetailsFromModel();
    }

    protected function saveTaxDetailsFromModel(): void
{
    $record = $this->record ?? null;

    if (!$record) {
        return;
    }

    // Reload items
    $record->load('items');
    $items = $record->items;

    if ($items->isEmpty()) {
        return;
    }

    // Delete existing tax details
    TaxDetail::where('taxable_type', get_class($record))
        ->where('taxable_id', $record->id)
        ->delete();

    $useCgstSgst = $this->shouldShowCgstSgst(fn ($field) => data_get($this->form->getState(), $field));
    $discountLevel = SalesDocumentPreference::first()?->discount_level ?? 'none';

    // --- Pre-calculation ---
    $grossTotal = 0;
    foreach ($items as $item) {
        $grossTotal += floatval($item->quantity ?? 1) * floatval($item->unit_price ?? 0);
    }

    $transactionDiscountAmount = 0;
    $lineItemDiscountedTotal = $grossTotal;
    $totalLineItemDiscount = 0; // 🚀 NEW: Track line item discount total
    if ($discountLevel === 'line_item' || $discountLevel === 'both') {
        $lineItemDiscountedTotal = 0;
        foreach ($items as $item) {
            $lineGross = floatval($item->quantity ?? 1) * floatval($item->unit_price ?? 0);
            $lineDiscount = $lineGross * (floatval($item->discount ?? 0) / 100);
            $lineItemDiscountedTotal += $lineGross - $lineDiscount;
            $totalLineItemDiscount += $lineDiscount; // 🚀 NEW: Add to line item discount total
        }
    }

    if ($discountLevel === 'transaction' || $discountLevel === 'both') {
        $discountTypeRaw = data_get($this->form->getState(), 'discount_type', 'percentage');
        $discountType = strtolower(trim($discountTypeRaw));
        $discountValue = floatval(data_get($this->form->getState(), 'discount_value', 0));

        $baseForTransactionDiscount = ($discountLevel === 'both') ? $lineItemDiscountedTotal : $grossTotal;

        if ($discountType === 'percentage' || $discountType === '%') {
            $transactionDiscountAmount = $baseForTransactionDiscount * ($discountValue / 100);
        } else {
            $transactionDiscountAmount = $discountValue;
        }
    }


    // --- Per item calculation ---
    foreach ($items as $item) {
        $quantity = floatval($item->quantity ?? 1);
        $unit_price = floatval($item->unit_price ?? 0);
        $discount = floatval($item->discount ?? 0);
        $taxRate = floatval($item->tax_rate ?? 0);
        $itemMasterId = $item->item_master_id ?? null;

        $lineGross = $quantity * $unit_price;
        $taxableAmount = $lineGross;

        if ($discountLevel === 'line_item' || $discountLevel === 'both') {
            // line level discount only
            $lineDiscount = $lineGross * ($discount / 100);
            $taxableAmount = $lineGross - $lineDiscount;
        }

        if ($discountLevel === 'transaction' || $discountLevel === 'both') {
            // allocate proportional transaction discount
            $baseForTransactionDiscount = ($discountLevel === 'both') ? $lineItemDiscountedTotal : $grossTotal;
            $proportion = $baseForTransactionDiscount > 0 ? ($taxableAmount / $baseForTransactionDiscount) : 0;
            $allocatedDiscount = $transactionDiscountAmount * $proportion;
            $taxableAmount = max(0, $taxableAmount - $allocatedDiscount);
        }

        if (! $itemMasterId || ! $taxRate) {
            continue;
        }

        $itemModel = ItemMaster::with('taxes.components')->find($itemMasterId);
        if (! $itemModel) {
            continue;
        }

        $tax = $itemModel->taxes->first(function ($tax) use ($taxRate) {
            return abs($tax->total_rate - $taxRate) < 0.0001;
        });

        if (! $tax || $tax->components->isEmpty()) {
            continue;
        }

        $components = $tax->components;

        if ($useCgstSgst) {
            foreach (['CGST', 'SGST'] as $type) {
                $component = $components->firstWhere('type', $type);
                if ($component) {
                    TaxDetail::create([
                        'taxable_type' => get_class($record),
                        'taxable_id'   => $record->id,
                        'tax_id'       => $tax->id,
                        'tax_component_id' => $component->id,
                        'type'         => $component->type,
                        'rate'         => $component->rate,
                        'amount'       => $taxableAmount * ($component->rate / 100),
                    ]);
                }
            }
        } else {
            $igst = $components->firstWhere('type', 'IGST');
            if ($igst) {
                TaxDetail::create([
                    'taxable_type' => get_class($record),
                    'taxable_id'   => $record->id,
                    'tax_id'       => $tax->id,
                    'tax_component_id' => $igst->id,
                    'type'         => $igst->type,
                    'rate'         => $igst->rate,
                    'amount'       => $taxableAmount * ($igst->rate / 100),
                ]);
            }
        }
    }
}


}

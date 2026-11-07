<?php

namespace App\Filament\Resources\Visits\Schemas;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Helpers\SalesDocumentQuickCreate;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\SgipDistribution;
use App\Models\Visit;
use App\Models\VisitOutcome;
use App\Models\VisitPreference;
use App\Services\DateFormatService;
use App\Services\Visit\DcrService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class VisitForm
{
    public static function configure(Schema $schema): Schema
    {
        $prefs = VisitPreference::current();

        return $schema
            ->components([
                Hidden::make('sales_dcr_id')
                    ->default(function (?Visit $record): int {
                        $date = $record?->visit_date?->format('Y-m-d') ?? now()->toDateString();

                        return (new DcrService)->getOrCreateForDate($date)->id;
                    }),
                Tabs::make('VisitTabs')
                    ->tabs([
                        // --- TAB 1: VISIT DETAILS ---
                        Tabs\Tab::make('Visit')
                            ->schema([
                                Group::make()
                                    ->schema([
                                        Group::make()
                                            ->schema([
                                                Forms\Components\TimePicker::make('start_time')
                                                    ->label('Check-in')
                                                    ->seconds(false)
                                                    ->visible(fn () => VisitPreference::current()->allow_manual_time_edit)
                                                    ->disabled(fn ($record) => ! VisitPreference::current()->allow_manual_time_edit || $record->isCompleted()),

                                                Forms\Components\TimePicker::make('end_time')
                                                    ->label('Check-out')
                                                    ->seconds(false)
                                                    ->visible(fn () => VisitPreference::current()->allow_manual_time_edit)
                                                    ->disabled(fn ($record) => ! VisitPreference::current()->allow_manual_time_edit || $record->isCompleted()),
                                            ])->columns(2),

                                        Select::make('visit_outcome_id')
                                            ->label('Outcome')
                                            ->options(
                                                VisitOutcome::query()
                                                    ->orderBy('label')
                                                    ->pluck('label', 'id')
                                            )
                                            ->live(debounce: 5000)
                                            ->searchable()
                                            ->visible(fn () => $prefs->isFieldVisible('outcome'))
                                            ->required(fn () => $prefs->isFieldRequired('outcome'))
                                            ->preload(),

                                        Forms\Components\Textarea::make('remarks')
                                            ->live(debounce: 5000)
                                            ->visible(fn () => $prefs->isFieldVisible('remarks'))
                                            ->required(fn () => $prefs->isFieldRequired('remarks'))
                                            ->columnSpanFull(),
                                    ]),

                                Select::make('visitPurposes')
                                    ->label('Purposes')
                                    ->relationship('visitPurposes', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->live(debounce: 5000)
                                    ->visible(fn () => $prefs->isFieldVisible('purpose'))
                                    ->required(fn () => $prefs->isFieldRequired('purpose'))
                                    ->native(false),

                                DatePicker::make('next_follow_up_date')
                                    ->label('Next Follow-Up Date')
                                    ->visible(fn () => $prefs->isFieldVisible('next_follow_up_date'))
                                    ->required(fn () => $prefs->isFieldRequired('next_follow_up_date')),

                                Toggle::make('is_joint_work')
                                    ->label('Is Joint Work?')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set, $state) => ! $state ? $set('jointUsers', []) : null),
                                Select::make('jointUsers')
                                    ->hiddenLabel()
                                    ->relationship('jointUsers', 'name')
                                    ->multiple()
                                    ->live(debounce: 5000)
                                    ->searchable()
                                    ->preload(),

                                Group::make()
                                    ->schema([
                                        TextEntry::make('visit_type')
                                            ->getStateUsing(fn ($record) => $record->visit_type
                                                ? Str::of(class_basename($record->visit_type))->snake()->replace('_', ' ')->title()
                                                : '—'
                                            )
                                            ->label('Type')
                                            ->inlineLabel(fn () => ! request()->header('User-Agent') || ! str_contains(request()->header('User-Agent'), 'Mobile')),

                                        TextEntry::make('patch_id')
                                            ->label('Patch')
                                            ->getStateUsing(fn ($record) => $record->patch?->name ?? '—')
                                            ->inlineLabel(fn () => ! request()->header('User-Agent') || ! str_contains(request()->header('User-Agent'), 'Mobile')),
                                        TextEntry::make('visit_date')
                                            ->label('Visit Date')
                                            ->getStateUsing(fn ($record) => $record->visit_date?->format(app(DateFormatService::class)->dateFormat()) ?? '—')
                                            ->inlineLabel(fn () => ! request()->header('User-Agent') || ! str_contains(request()->header('User-Agent'), 'Mobile')),
                                    ])->columns([
                                        'default' => 3, // 📱 This forces 2 columns on mobile/extra small screens
                                        'sm' => 3,
                                        'lg' => 3,
                                    ]),

                            ]),

                        // --- TAB 2: FEEDBACK ---
                        Tabs\Tab::make('Feedback')
                            ->schema([
                                Repeater::make('feedbacks')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->schema([
                                        // Using a ViewField or a simple Text component for the question
                                        TextEntry::make('question_text')
                                            ->hiddenLabel()
                                            ->state(fn ($record) => $record?->question?->question),

                                        Forms\Components\Radio::make('answer')
                                            ->options([
                                                1 => '1',
                                                2 => '2',
                                                3 => '3',
                                                4 => '4',
                                                5 => '5',
                                            ])
                                            ->inline()
                                            ->columns(5)
                                            ->live(debounce: 5000)
                                            ->hiddenLabel()
                                            ->extraAttributes(['class' => 'text-xs']),
                                    ])
                                    ->addable(false)
                                    ->deletable(false),
                            ]),

                        // --- TAB 3: CUSTOMER ---
                        Tabs\Tab::make('Customer')
                            ->schema([
                                Group::make()
                                    ->schema([
                                        ViewField::make('account_summary')
                                            ->view('filament.visits.account-summary'),
                                    ])
                                    ->visible(fn ($record) => filled($record?->primaryCompany())),
                            ]),

                        // --- TAB 4: EXPENSES ---
                        Tabs\Tab::make('Expenses')
                            ->schema([
                                ViewField::make('expenses_summary')
                                    ->view('filament.visits.expenses-summary'),
                            ]),

                        // --- TAB 5: SGIP ---
                        Tabs\Tab::make('SGIP')
                            ->schema([
                                Actions::make([
                                    Action::make('create_sgip_distribution')
                                        ->label(fn (?Visit $record): string => $record?->sgipDistribution ? 'Open SGIP Distribution' : 'Create SGIP Distribution')
                                        ->icon('heroicon-o-gift')
                                        ->color('primary')
                                        ->visible(fn (?Visit $record): bool => filled($record))
                                        ->action(function (Visit $record): void {
                                            $account = $record->primaryCompany();

                                            if (! $account) {
                                                Notification::make()
                                                    ->title('Select a customer before creating SGIP distribution.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            $distribution = SgipDistribution::query()->firstOrCreate(
                                                ['visit_id' => $record->id],
                                                [
                                                    'user_id' => $record->employee_id ?? Auth::id(),
                                                    'employee_id' => $record->employee?->employee_id,
                                                    'account_master_id' => $account->id,
                                                    'territory_id' => $record->territory_id,
                                                    'sales_tour_plan_id' => $record->sales_tour_plan_id,
                                                    'visit_date' => $record->visit_date ?? today(),
                                                    'approval_status' => 'draft',
                                                ]
                                            );

                                            redirect(
                                                SgipDistributionResource::getUrl('edit', [
                                                    'record' => $distribution,
                                                ])
                                            );
                                        }),
                                ])->columnSpanFull(),

                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('sgip_distribution_status')
                                            ->label('Status')
                                            ->badge()
                                            ->state(fn (?Visit $record): string => $record?->sgipDistribution?->approval_status ?? 'Not Created'),

                                        TextEntry::make('sgip_distribution_total')
                                            ->label('Total Value')
                                            ->money('INR')
                                            ->state(fn (?Visit $record): float => (float) ($record?->sgipDistribution?->total_value ?? 0)),

                                        TextEntry::make('sgip_distribution_items')
                                            ->label('Items')
                                            ->state(fn (?Visit $record): int => $record?->sgipDistribution?->items()->count() ?? 0),

                                        Action::make('view_details')
                                            ->label('Details')
                                            ->icon('heroicon-o-document-text')
                                            ->link()
                                            ->modalHeading(fn (Visit $record): string => "Sample / Gift / Input Details for {$record->document_number}")
                                            ->color('info')
                                            ->modalWidth('4xl')
                                            ->slideOver()
                                            ->visible(fn (?Visit $record): bool => ($record?->sgipDistribution?->items()->count() ?? 0) > 0)
                                            ->modalSubmitAction(false)
                                            ->modalCancelActionLabel('Close')
                                            ->schema([
                                                Section::make('Distribution Summary')
                                                    ->columns(3)
                                                    ->compact()
                                                    ->schema([
                                                        TextEntry::make('sgipDistribution.doctor.name')
                                                            ->label('Doctor')
                                                            ->placeholder('-'),

                                                        TextEntry::make('sgipDistribution.visit_date')
                                                            ->label('Visit Date')
                                                            ->date()
                                                            ->placeholder('-'),

                                                        TextEntry::make('sgipDistribution.approval_status')
                                                            ->label('Approval Status')
                                                            ->badge()
                                                            ->placeholder('-'),

                                                        TextEntry::make('sgipDistribution.user.employee.full_name')
                                                            ->label('Sales Employee')
                                                            ->placeholder('-'),

                                                        TextEntry::make('sgipDistribution.territory.name')
                                                            ->label('Territory')
                                                            ->placeholder('-'),

                                                        TextEntry::make('sgipDistribution.total_value')
                                                            ->label('Total Value')
                                                            ->money('INR')
                                                            ->placeholder('-'),
                                                    ]),

                                                RepeatableEntry::make('sgipDistribution.items')
                                                    ->label('Samples / Gifts / Inputs')
                                                    ->schema([
                                                        Section::make(fn ($record): string => $record->item?->item_name ?? 'Item')
                                                            ->columns(3)
                                                            ->compact()
                                                            ->schema([
                                                                TextEntry::make('item.item_code')
                                                                    ->label('Item Code')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('item.item_name')
                                                                    ->label('Item Name')
                                                                    ->weight('medium')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('item.sku')
                                                                    ->label('SKU')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('item.brand.name')
                                                                    ->label('Brand')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('item.category.name')
                                                                    ->label('Category')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('item.unitOfMeasurement.name')
                                                                    ->label('UOM')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('quantity')
                                                                    ->label('Quantity')
                                                                    ->numeric()
                                                                    ->placeholder('-'),

                                                                TextEntry::make('unit_value')
                                                                    ->label('Unit Value')
                                                                    ->money('INR')
                                                                    ->placeholder('-'),

                                                                TextEntry::make('total_value')
                                                                    ->label('Total Value')
                                                                    ->money('INR')
                                                                    ->placeholder('-'),
                                                            ]),
                                                    ])
                                                    ->contained()
                                                    ->columns(1),
                                            ]),

                                    ]),
                            ]),

                        // --- TAB 6: ATTACHMENTS ---
                        Tabs\Tab::make('Attachments')
                            ->schema([

                                Repeater::make('media')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->schema([
                                        FileUpload::make('path')
                                            ->label('Visit Photo')
                                            ->image()
                                            ->directory('visits/photos')
                                            ->disk('public')
                                            ->maxSize(2048)
                                            ->imageResizeTargetWidth(800)
                                            ->imageResizeTargetHeight(800)
                                            ->openable()
                                            ->downloadable()
                                            ->required()
                                            ->live(), // only for UI refresh

                                        Select::make('tags')
                                            ->relationship('tags', 'name')
                                            ->multiple()
                                            ->searchable()
                                            ->preload(),

                                        TextEntry::make('processing_status')
                                            ->state(fn ($record) => $record?->processing_status ?? 'Pending')
                                            ->visible(fn ($record) => filled($record)),

                                        Hidden::make('original_name'),
                                        Hidden::make('mime_type'),
                                        Hidden::make('size'),
                                        Hidden::make('latitude'),
                                        Hidden::make('longitude'),
                                    ])
                                    ->columns(2),

                            ]),

                        // --- TAB 7: SALES DOCUMENTS ---
                        Tabs\Tab::make('Sales Documents')
                            ->schema([
                                Actions::make([
                                    Action::make('create_quote')
                                        ->label('Create Quote')
                                        ->icon('heroicon-o-document-text')
                                        ->color('success')
                                        ->visible(fn (?Visit $record) => filled($record))
                                        ->action(function (Visit $record) {

                                            $quote = SalesDocumentQuickCreate::createFromVisit(
                                                $record,
                                                Quote::class,
                                            );

                                            redirect(
                                                QuoteResource::getUrl(
                                                    'edit',
                                                    ['record' => $quote]
                                                )
                                            );
                                        }),

                                    Action::make('create_sales_order')
                                        ->label('Create Sales Order')
                                        ->icon('heroicon-o-shopping-cart')
                                        ->color('warning')
                                        ->action(function (Visit $record) {

                                            $salesOrder = SalesDocumentQuickCreate::createFromVisit(
                                                $record,
                                                SalesOrder::class,
                                            );

                                            redirect(
                                                SalesOrderResource::getUrl(
                                                    'edit',
                                                    ['record' => $salesOrder]
                                                )
                                            );
                                        }),

                                ])->columnSpanFull(),

                                Grid::make(2)
                                    ->schema([

                                        Section::make('Quotes')
                                            ->schema([
                                                TextEntry::make('quote_count')
                                                    ->label('Total Quotes')
                                                    ->state(fn ($record) => $record?->quoteSummary()['count'] ?? 0
                                                    ),

                                                TextEntry::make('quote_amount')
                                                    ->label('Quote Amount')
                                                    ->state(fn ($record) => $record?->quoteSummary()['amount'] ?? 0
                                                    )
                                                    ->money('INR'),

                                                TextEntry::make('quote_items')
                                                    ->label('Items')
                                                    ->state(fn ($record) => $record?->quoteSummary()['items'] ?? 0
                                                    ),
                                            ]),

                                        Section::make('Sales Orders')
                                            ->schema([
                                                TextEntry::make('so_count')
                                                    ->label('Total Orders')
                                                    ->state(fn ($record) => $record?->salesOrderSummary()['count'] ?? 0
                                                    ),

                                                TextEntry::make('so_amount')
                                                    ->label('Order Amount')
                                                    ->state(fn ($record) => $record?->salesOrderSummary()['amount'] ?? 0
                                                    )
                                                    ->money('INR'),

                                                TextEntry::make('so_items')
                                                    ->label('Items')
                                                    ->state(fn ($record) => $record?->salesOrderSummary()['items'] ?? 0
                                                    ),
                                            ]),

                                        Section::make('Linked Documents')
                                            ->schema([
                                                RepeatableEntry::make('visitDocumentLinks')
                                                    ->hiddenLabel()
                                                    ->contained()
                                                    ->schema([

                                                        TextEntry::make('documentable_type')
                                                            ->label('Type')
                                                            ->badge()
                                                            ->formatStateUsing(
                                                                fn ($state) => class_basename($state)
                                                            ),

                                                        TextEntry::make('documentable.document_number')
                                                            ->label('Document No'),

                                                        TextEntry::make('documentable.status')
                                                            ->badge(),

                                                        TextEntry::make('documentable.total')
                                                            ->money('INR')
                                                            ->label('Amount'),

                                                        Actions::make([
                                                            Action::make('open')
                                                                ->link()
                                                                ->icon('heroicon-o-arrow-top-right-on-square')
                                                                ->url(function ($record) {

                                                                    $document = $record->documentable;

                                                                    return match (get_class($document)) {
                                                                        Quote::class => QuoteResource::getUrl('edit', [
                                                                            'record' => $document,
                                                                        ]),

                                                                        SalesOrder::class => SalesOrderResource::getUrl('edit', [
                                                                            'record' => $document,
                                                                        ]),

                                                                        default => '#',
                                                                    };
                                                                }),
                                                        ]),
                                                    ])
                                                    ->columns(5),

                                            ])
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}

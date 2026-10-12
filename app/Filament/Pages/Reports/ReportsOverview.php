<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Pages\Reports\Concerns\AuthorizesReportAccess;
use App\Filament\Resources\InventoryStocks\InventoryStockResource;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ReportsOverview extends Page
{
    use AuthorizesReportAccess;

    protected static ?string $cluster = ReportsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports.overview';

    /**
     * @return list<array{label: string, description: string, icon: Heroicon, reports: list<array{label: string, description: string, url: string|null, icon: Heroicon}>}>
     */
    public function getCatalogGroups(): array
    {
        return [
            [
                'label' => 'Field activity',
                'description' => 'Daily calls, coverage against the tour plan, and expenses.',
                'icon' => Heroicon::OutlinedClipboardDocumentList,
                'reports' => [
                    [
                        'label' => 'DCR register',
                        'description' => 'Daily call reports with visit counts, distance, expense, and approval status.',
                        'url' => DcrRegister::getUrl(),
                        'icon' => Heroicon::OutlinedClipboardDocumentList,
                    ],
                    [
                        'label' => 'Visit coverage',
                        'description' => 'Tour plan accounts versus completed visits.',
                        'url' => VisitCoverage::getUrl(),
                        'icon' => Heroicon::OutlinedMap,
                    ],
                    [
                        'label' => 'Expense summary',
                        'description' => 'Field expenses by representative, type, and approval outcome.',
                        'url' => ExpenseSummary::getUrl(),
                        'icon' => Heroicon::OutlinedCurrencyRupee,
                    ],
                ],
            ],
            [
                'label' => 'Samples and stock',
                'description' => 'Doctor issues, compliance exceptions, and inventory movement.',
                'icon' => Heroicon::OutlinedCube,
                'reports' => [
                    [
                        'label' => 'Doctor Sample Ledger',
                        'description' => 'SGIP sample, gift, and promotional lines by doctor and campaign.',
                        'url' => DoctorSampleLedger::getUrl(),
                        'icon' => Heroicon::OutlinedGift,
                    ],
                    [
                        'label' => 'SGIP compliance',
                        'description' => 'Limit and campaign quota violations on sample distributions.',
                        'url' => SgipCompliance::getUrl(),
                        'icon' => Heroicon::OutlinedShieldExclamation,
                    ],
                    [
                        'label' => 'Stock movements',
                        'description' => 'Inventory receipts, issues, transfers, and SGIP deductions by location.',
                        'url' => StockMovementRegister::getUrl(),
                        'icon' => Heroicon::OutlinedArrowsRightLeft,
                    ],
                    [
                        'label' => 'Stock on hand',
                        'description' => 'Current quantity by item and location.',
                        'url' => InventoryStockResource::getUrl('index'),
                        'icon' => Heroicon::OutlinedCircleStack,
                    ],
                ],
            ],
            [
                'label' => 'Sales',
                'description' => 'Pipeline, documents, and follow-ups. Totals only — not AR aging.',
                'icon' => Heroicon::OutlinedChartBar,
                'reports' => [
                    [
                        'label' => 'Lead pipeline',
                        'description' => 'Open leads by status, owner, territory, and value.',
                        'url' => LeadPipeline::getUrl(),
                        'icon' => Heroicon::OutlinedFunnel,
                    ],
                    [
                        'label' => 'Deal pipeline',
                        'description' => 'Deals by stage, amount, and expected close.',
                        'url' => DealPipeline::getUrl(),
                        'icon' => Heroicon::OutlinedBriefcase,
                    ],
                    [
                        'label' => 'Sales document register',
                        'description' => 'Quotes, orders, and invoices by status and total.',
                        'url' => SalesDocumentRegister::getUrl(),
                        'icon' => Heroicon::OutlinedDocumentText,
                    ],
                    [
                        'label' => 'Overdue follow-ups',
                        'description' => 'Open follow-ups whose next date is in the past.',
                        'url' => OverdueFollowUps::getUrl(),
                        'icon' => Heroicon::OutlinedClock,
                    ],
                ],
            ],
            [
                'label' => 'HR',
                'description' => 'Attendance, punch versus tour, and leave movements.',
                'icon' => Heroicon::OutlinedUsers,
                'reports' => [
                    [
                        'label' => 'Attendance register',
                        'description' => 'Daily punch status, first in, and last out.',
                        'url' => AttendanceRegister::getUrl(),
                        'icon' => Heroicon::OutlinedCalendarDays,
                    ],
                    [
                        'label' => 'Punch vs tour',
                        'description' => 'Punched in with no visit, or a visit with no punch.',
                        'url' => PunchVsTour::getUrl(),
                        'icon' => Heroicon::OutlinedExclamationTriangle,
                    ],
                    [
                        'label' => 'Leave ledger',
                        'description' => 'Unified leave applications, adjustments, encashments, and lapses.',
                        'url' => LeaveLedger::getUrl(),
                        'icon' => Heroicon::OutlinedCalendar,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{label: string, description: string, url: string|null, icon: Heroicon}>
     */
    public function getCatalog(): array
    {
        return collect($this->getCatalogGroups())
            ->pluck('reports')
            ->flatten(1)
            ->values()
            ->all();
    }
}

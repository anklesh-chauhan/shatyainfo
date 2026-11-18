<?php

namespace App\Services\Mobile;

use App\Filament\Clusters\HR\AttendanceCluster;
use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FieldCoverage;
use App\Filament\Pages\FlexiblePlanning;
use App\Filament\Pages\MyApprovals;
use App\Filament\Pages\PunchIn;
use App\Filament\Pages\SalesAssistant;
use App\Filament\Pages\TodaysTour;
use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\EDetailingMaterials\EDetailingMaterialResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\RcpaObservations\RcpaObservationResource;
use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Filament\Resources\TerritorySalesTargets\TerritorySalesTargetResource;
use App\Filament\Resources\Visits\VisitResource;
use BackedEnum;
use Filament\Facades\Filament;
use ReflectionProperty;
use UnitEnum;

class MobileNavigationRegistry
{
    public const SCHEMA_VERSION = 1;

    /**
     * Mobile groups in Admin order. Settings & Administration is web-only.
     *
     * @return list<array{key: string, label: string, sort: int, collapsed: bool}>
     */
    public function groups(): array
    {
        $labels = [
            'workspace' => 'My Workspace',
            'field' => 'Field Operations',
            'customers' => 'Customers & Territories',
            'sales' => 'Sales & CRM',
            'secondary' => 'Secondary Sales',
            'marketing' => 'Marketing & Samples',
            'inventory' => 'Inventory & Purchasing',
            'hr' => 'HR & Attendance',
            'reports' => 'Reports',
        ];

        $groups = [];
        $sort = 10;

        foreach ($labels as $key => $label) {
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'sort' => $sort,
                'collapsed' => $key !== 'workspace',
            ];
            $sort += 10;
        }

        return $groups;
    }

    /**
     * Admin sidebar groups. The last entry stays on the web.
     *
     * @return list<array{key: string, label: string, sort: int, collapsed: bool}>
     */
    public function webGroups(): array
    {
        return [
            ...$this->groups(),
            [
                'key' => 'settings',
                'label' => 'Settings & Administration',
                'sort' => 100,
                'collapsed' => true,
            ],
        ];
    }

    /**
     * Schema 1 screens with negotiated cluster and sort metadata.
     *
     * @return list<array{key: string, label: string, sort: int, group: string, screen_key: string, source: class-string, permission_key: string, parent: array{key: string, label: string, sort: int, source: class-string}|null}>
     */
    public function entries(bool $adminOrder = false): array
    {
        $groupSort = [];

        foreach ($this->groups() as $group) {
            $groupSort[$group['key']] = $group['sort'];
        }

        $rank = [];
        if ($adminOrder) {
            $panel = Filament::getPanel('admin');
            $rank = array_flip([...$panel->getPages(), ...$panel->getResources()]);
        }

        $bindings = $this->bindings();
        if ($adminOrder) {
            $bindings[] = ['key' => 'g01-01', 'group' => 'workspace', 'screen_key' => 'dashboard', 'source' => Dashboard::class];
            $bindings[] = ['key' => 'g01-04', 'group' => 'workspace', 'screen_key' => 'my-approvals', 'source' => MyApprovals::class];
            $bindings[] = ['key' => 'g01-05', 'group' => 'workspace', 'screen_key' => 'approval-register', 'source' => ApprovalResource::class];
        }

        $entries = array_map(function (array $binding) use ($adminOrder): array {
            $metadata = $this->navigationMetadata($binding['source']);

            return [
                'key' => $binding['key'],
                'label' => $metadata['label'] ?? (string) $binding['source']::getNavigationLabel(),
                'sort' => $adminOrder ? $binding['source']::getNavigationSort() ?? -1 : $metadata['sort'],
                'parent' => $adminOrder ? $this->parentFor($binding['source']) : null,
                'group' => $binding['group'],
                'screen_key' => $binding['screen_key'],
                'source' => $binding['source'],
                'permission_key' => $this->permissionKey($binding['source']),
            ];
        }, $bindings);

        usort($entries, function (array $left, array $right) use ($groupSort, $rank, $adminOrder): int {
            $byGroup = ($groupSort[$left['group']] ?? PHP_INT_MAX) <=> ($groupSort[$right['group']] ?? PHP_INT_MAX);

            if ($byGroup !== 0) {
                return $byGroup;
            }

            $leftSort = is_int($left['sort']) ? $left['sort'] : PHP_INT_MAX;
            $rightSort = is_int($right['sort']) ? $right['sort'] : PHP_INT_MAX;
            $bySort = $leftSort <=> $rightSort;

            if ($adminOrder) {
                $leftSort = $left['parent']['sort'] ?? $leftSort;
                $rightSort = $right['parent']['sort'] ?? $rightSort;
                $leftParent = $left['parent']['source'] ?? $left['source'];
                $rightParent = $right['parent']['source'] ?? $right['source'];

                return [$leftSort, $rank[$leftParent] ?? PHP_INT_MAX, $left['sort'], $rank[$left['source']] ?? PHP_INT_MAX, $left['key']]
                    <=> [$rightSort, $rank[$rightParent] ?? PHP_INT_MAX, $right['sort'], $rank[$right['source']] ?? PHP_INT_MAX, $right['key']];
            }

            return $bySort !== 0 ? $bySort : $left['key'] <=> $right['key'];
        });

        return $entries;
    }

    /**
     * Ordered groups, including groups that have no schema 1 screen yet.
     *
     * @return list<array{key: string, label: string, sort: int, items: list<array{key: string, label: string, sort: int, screen_key: string}>}>
     */
    public function orderedGroups(): array
    {
        $items = [];

        foreach ($this->entries() as $entry) {
            $items[$entry['group']][] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'sort' => $entry['sort'],
                'screen_key' => $entry['screen_key'],
            ];
        }

        $groups = $this->groups();
        usort($groups, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return array_map(fn (array $group): array => [
            'key' => $group['key'],
            'label' => $group['label'],
            'sort' => $group['sort'],
            'items' => $items[$group['key']] ?? [],
        ], $groups);
    }

    /** @return array{key: string, label: string, sort: int, source: class-string}|null */
    public function parentFor(string $source): ?array
    {
        $cluster = $source::getCluster();
        $key = match ($cluster) {
            EmployeeManagementCluster::class => 'employee-management',
            AttendanceCluster::class => 'attendance',
            LeaveManagementCluster::class => 'leave-management',
            ReportsCluster::class => 'all-reports',
            default => null,
        };

        return $key === null ? null : [
            'key' => $key,
            'label' => (string) $cluster::getNavigationLabel(),
            'sort' => $cluster::getNavigationSort() ?? -1,
            'source' => $cluster,
        ];
    }

    public function standaloneDestination(string $itemKey): ?string
    {
        return match ($itemKey) {
            'g01-02' => 'punch',
            'g02-03' => 'visit-register',
            'g02-06' => 'expense-register',
            'g06-03' => 'e-detailing-library',
            'g06-07' => 'sgip-register',
            default => null,
        };
    }

    public function homeFor(array $screenKeys): string
    {
        return in_array('todays-tour', $screenKeys, true) ? 'todays-tour' : 'modules';
    }

    public function permissionKey(string $source): string
    {
        $basename = basename(str_replace('\\', '/', $source));

        return str_replace('Resource', '', $basename);
    }

    /**
     * Differences between this registry and the current Admin navigation.
     *
     * @return list<string>
     */
    public function drift(): array
    {
        $issues = [];
        $groups = $this->groups();
        $expectedKeys = ['workspace', 'field', 'customers', 'sales', 'secondary', 'marketing', 'inventory', 'hr', 'reports'];

        if (array_column($groups, 'key') !== $expectedKeys) {
            $issues[] = 'Mobile groups are not the nine contract groups in order.';
        }

        foreach ($groups as $group) {
            if ($group['key'] === 'settings' || $group['label'] === 'Settings & Administration') {
                $issues[] = 'Settings & Administration is in the mobile registry.';
            }
        }

        $web = $this->webGroups();
        $settings = $web[9] ?? null;

        if (($settings['key'] ?? null) !== 'settings' || ($settings['label'] ?? null) !== 'Settings & Administration') {
            $issues[] = 'Web navigation does not keep Settings & Administration last.';
        }

        $screens = [];

        foreach ($this->bindings() as $binding) {
            $screens[$binding['screen_key']][] = $binding['key'];
            $metadata = $this->navigationMetadata($binding['source']);
            $expectedLabel = null;

            foreach ($groups as $group) {
                if ($group['key'] === $binding['group']) {
                    $expectedLabel = $group['label'];
                }
            }

            if ($metadata['group'] !== $expectedLabel) {
                $issues[] = "{$binding['key']} navigation group is {$metadata['group']}, expected {$expectedLabel}.";
            }

            if (! is_int($metadata['sort'])) {
                $issues[] = "{$binding['key']} has no navigation sort.";
            }

            if (! is_string($metadata['label']) || $metadata['label'] === '') {
                $issues[] = "{$binding['key']} has no navigation label.";
            }
        }

        $expectedScreens = [
            'todays-tour' => ['g01-02', 'g01-03'],
            'sales-assistant' => ['g01-06'],
            'mtp' => ['g02-01'],
            'flexible-planning' => ['g02-02'],
            'visit' => ['g02-03'],
            'dcr' => ['g02-04', 'g02-06'],
            'field-coverage' => ['g02-07'],
            'accounts' => ['g03-01'],
            'leads' => ['g04-01'],
            'deals' => ['g04-04'],
            'e-detailing' => ['g06-03'],
            'rcpa' => ['g06-04'],
            'sgip' => ['g06-07'],
            'sales-performance' => ['g04-09'],
        ];

        ksort($screens);
        ksort($expectedScreens);

        foreach ($screens as $screenKey => $itemKeys) {
            sort($screens[$screenKey]);
        }

        if ($screens !== $expectedScreens) {
            $issues[] = 'Screen keys do not match the schema 1 contract.';
        }

        return $issues;
    }

    /**
     * @return list<array{key: string, group: string, screen_key: string, source: class-string}>
     */
    private function bindings(): array
    {
        return [
            ['key' => 'g01-02', 'group' => 'workspace', 'screen_key' => 'todays-tour', 'source' => PunchIn::class],
            ['key' => 'g01-03', 'group' => 'workspace', 'screen_key' => 'todays-tour', 'source' => TodaysTour::class],
            ['key' => 'g01-06', 'group' => 'workspace', 'screen_key' => 'sales-assistant', 'source' => SalesAssistant::class],
            ['key' => 'g02-01', 'group' => 'field', 'screen_key' => 'mtp', 'source' => SalesTourPlanResource::class],
            ['key' => 'g02-02', 'group' => 'field', 'screen_key' => 'flexible-planning', 'source' => FlexiblePlanning::class],
            ['key' => 'g02-03', 'group' => 'field', 'screen_key' => 'visit', 'source' => VisitResource::class],
            ['key' => 'g02-04', 'group' => 'field', 'screen_key' => 'dcr', 'source' => SalesDcrResource::class],
            ['key' => 'g02-06', 'group' => 'field', 'screen_key' => 'dcr', 'source' => SalesDcrExpenseResource::class],
            ['key' => 'g02-07', 'group' => 'field', 'screen_key' => 'field-coverage', 'source' => FieldCoverage::class],
            ['key' => 'g03-01', 'group' => 'customers', 'screen_key' => 'accounts', 'source' => AccountMasterResource::class],
            ['key' => 'g04-01', 'group' => 'sales', 'screen_key' => 'leads', 'source' => LeadResource::class],
            ['key' => 'g04-04', 'group' => 'sales', 'screen_key' => 'deals', 'source' => DealResource::class],
            ['key' => 'g04-09', 'group' => 'sales', 'screen_key' => 'sales-performance', 'source' => TerritorySalesTargetResource::class],
            ['key' => 'g06-03', 'group' => 'marketing', 'screen_key' => 'e-detailing', 'source' => EDetailingMaterialResource::class],
            ['key' => 'g06-04', 'group' => 'marketing', 'screen_key' => 'rcpa', 'source' => RcpaObservationResource::class],
            ['key' => 'g06-07', 'group' => 'marketing', 'screen_key' => 'sgip', 'source' => SgipDistributionResource::class],
        ];
    }

    /**
     * @param  class-string  $source
     * @return array{group: ?string, sort: mixed, label: mixed}
     */
    private function navigationMetadata(string $source): array
    {
        return [
            'group' => $this->groupLabel($this->staticProperty($source, 'navigationGroup')),
            'sort' => $this->staticProperty($source, 'navigationSort'),
            'label' => $this->staticProperty($source, 'navigationLabel'),
        ];
    }

    private function staticProperty(string $source, string $property): mixed
    {
        $reflection = new ReflectionProperty($source, $property);

        return $reflection->getValue();
    }

    private function groupLabel(mixed $group): ?string
    {
        if ($group instanceof BackedEnum) {
            return (string) $group->value;
        }

        if ($group instanceof UnitEnum) {
            return $group->name;
        }

        return is_string($group) ? $group : null;
    }
}

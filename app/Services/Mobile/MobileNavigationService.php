<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Field\SalesPerformanceService;
use App\Services\OptionalModuleService;
use Filament\Resources\Resource;

class MobileNavigationService
{
    public function __construct(
        private MobileNavigationRegistry $registry,
        private OptionalModuleService $modules,
    ) {}

    /**
     * @return array{
     *     schema_version: int,
     *     context: array{user_id: int, company_key: string},
     *     home: string,
     *     groups: list<array{key: string, label: string, sort: int, items: list<array{key: string, label: string, sort: int, screen_key: string}>}>
     * }
     */
    public function forUser(User $user, bool $standalone = false): array
    {
        $guard = auth()->getDefaultDriver();
        auth()->shouldUse('web');
        auth()->setUser($user);

        try {
            return $this->payload($user, $standalone);
        } finally {
            auth()->shouldUse($guard);
        }
    }

    /**
     * @return array{
     *     schema_version: int,
     *     context: array{user_id: int, company_key: string},
     *     home: string,
     *     groups: list<array{key: string, label: string, sort: int, items: list<array{key: string, label: string, sort: int, screen_key: string}>}>
     * }
     */
    private function payload(User $user, bool $standalone): array
    {
        $items = [];

        foreach ($this->registry->entries($standalone) as $entry) {
            if (! $this->visible($entry, $standalone)) {
                continue;
            }

            $parent = $entry['parent'];
            if ($parent !== null) {
                unset($parent['source']);
            }
            $destination = $standalone ? $this->registry->standaloneDestination($entry['key']) : null;
            $items[$entry['group']][] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'sort' => $entry['sort'],
                'screen_key' => $entry['screen_key'],
                ...($parent === null ? [] : ['parent' => $parent]),
                ...($destination === null ? [] : ['standalone_screen_key' => $destination]),
            ];
        }

        $groups = [];
        $screenKeys = [];

        foreach ($this->registry->groups() as $group) {
            if ($group['key'] === 'settings' || $group['label'] === 'Settings & Administration') {
                continue;
            }

            $groupItems = $items[$group['key']] ?? [];

            if ($groupItems === []) {
                continue;
            }

            foreach ($groupItems as $item) {
                $screenKeys[] = $item['screen_key'];
            }

            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'sort' => $group['sort'],
                'items' => $groupItems,
            ];
        }

        return [
            'schema_version' => MobileNavigationRegistry::SCHEMA_VERSION,
            'context' => [
                'user_id' => $user->id,
                'company_key' => $this->companyKey(),
            ],
            'home' => $standalone && ! collect($items['workspace'] ?? [])->contains('key', 'g01-03')
                ? 'modules' : $this->registry->homeFor($screenKeys),
            'groups' => $groups,
            ...($standalone ? ['quick_links' => true] : []),
        ];
    }

    /**
     * @param  array{key: string, permission_key: string, screen_key: string, source: class-string}  $entry
     */
    private function visible(array $entry, bool $standalone): bool
    {
        if (! $this->modules->navigationEnabled($entry['permission_key'])) {
            return false;
        }

        if ($entry['screen_key'] === 'dcr'
            && ! auth()->user()?->can(['ViewAny:SalesDcr', 'View:SalesDcr'])) {
            return false;
        }

        if ($standalone && $entry['screen_key'] === 'visit'
            && ! auth()->user()?->can(['ViewAny:Visit', 'View:Visit'])) {
            return false;
        }

        if ($entry['screen_key'] === 'travel-segments' && ! auth()->user()?->can('View:TravelSegment')) {
            return false;
        }

        if ($standalone && $entry['key'] === 'g02-06' && ! auth()->user()?->can('View:SalesDcrExpense')) {
            return false;
        }

        if ($entry['screen_key'] === 'territory-sales-assignments'
            && ! SalesPerformanceService::canViewTerritories(auth()->user())) {
            return false;
        }

        if ($entry['screen_key'] === 'follow-ups' && ! auth()->user()?->can('View:FollowUp')) {
            return false;
        }

        if ($entry['screen_key'] === 'lead-activities' && ! auth()->user()?->can('View:LeadActivity')) {
            return false;
        }

        if ($entry['screen_key'] === 'quotes' && ! auth()->user()?->can('View:Quote')) {
            return false;
        }

        if ($entry['screen_key'] === 'sales-orders' && ! auth()->user()?->can('View:SalesOrder')) {
            return false;
        }

        if ($entry['screen_key'] === 'sales-invoices' && ! auth()->user()?->can('View:SalesInvoice')) {
            return false;
        }

        if ($entry['screen_key'] === 'customer-prices' && ! auth()->user()?->can('View:CustomerPrice')) {
            return false;
        }

        $source = $entry['source'];

        if (! $source::shouldRegisterNavigation()) {
            return false;
        }

        if (is_subclass_of($source, Resource::class)) {
            return $source::canViewAny();
        }

        return $source::canAccess();
    }

    private function companyKey(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'landlord' : 'tenant:'.$tenant->getKey();
    }
}

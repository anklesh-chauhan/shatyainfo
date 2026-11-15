<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Models\User;
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
    public function forUser(User $user): array
    {
        $guard = auth()->getDefaultDriver();
        auth()->shouldUse('web');
        auth()->setUser($user);

        try {
            return $this->payload($user);
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
    private function payload(User $user): array
    {
        $items = [];

        foreach ($this->registry->entries() as $entry) {
            if (! $this->visible($entry)) {
                continue;
            }

            $items[$entry['group']][] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'sort' => $entry['sort'],
                'screen_key' => $entry['screen_key'],
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
            'home' => $this->registry->homeFor($screenKeys),
            'groups' => $groups,
        ];
    }

    /**
     * @param  array{permission_key: string, source: class-string}  $entry
     */
    private function visible(array $entry): bool
    {
        if (! $this->modules->navigationEnabled($entry['permission_key'])) {
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

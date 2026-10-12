<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Filament\Resources\ContactDetails\ContactDetailResource;
use App\Filament\Resources\ItemMasters\ItemMasterResource;
use App\Filament\Resources\Leads\LeadResource;
use Filament\GlobalSearch\GlobalSearchResult;

class GlobalSearchResource
{
    public static function getGlobalSearchResults(string $search): array
    {
        $results = [];

        // ✅ Get search results from resources
        foreach (self::getSearchableResources() as $resource) {
            $results = array_merge($results, $resource::getGlobalSearchResults($search));
        }

        // ✅ Add module index pages if search matches module name
        $modules = [
            'Leads' => 'filament.admin.resources.leads.index',
            'Accounts' => 'filament.admin.resources.account-masters.index',
            'Contacts' => 'filament.admin.resources.contacts.index',
            'Items' => 'filament.admin.resources.items.index',
        ];

        foreach ($modules as $name => $routeName) {
            if (strlen($search) >= 3 && stripos($name, $search) !== false) {
                $results[] = new GlobalSearchResult(
                    title: "View All {$name}",
                    url: route($routeName),
                );
            }
        }

        return $results;
    }

    protected static function getSearchableResources(): array
    {
        return [
            LeadResource::class,
            AccountMasterResource::class,
            ContactDetailResource::class,
            ItemMasterResource::class,
        ];
    }
}

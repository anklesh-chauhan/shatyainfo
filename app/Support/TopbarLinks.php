<?php

namespace App\Support;

use App\Filament\Pages\MyQuickLinks;
use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Filament\Resources\EDetailingMaterials\EDetailingMaterialResource;
use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

class TopbarLinks
{
    public function catalog(): array
    {
        $panel = Filament::getCurrentPanel();
        if (! $panel || ! Filament::auth()->check()) {
            return [];
        }
        $links = [];
        foreach (array_unique([...$panel->getResources(), ...$panel->getPages()]) as $class) {
            if ($class === MyQuickLinks::class || ! $class::shouldRegisterNavigation() || ! $class::canAccess()) {
                continue;
            }
            $resource = is_subclass_of($class, Resource::class);
            if ($resource && ! isset($class::getPages()['index'])) {
                continue;
            }
            $links[$class] = [
                'label' => $class::getNavigationLabel(),
                'url' => $resource ? $class::getUrl('index', panel: $panel->getId()) : $class::getUrl(panel: $panel->getId()),
            ];
        }
        uasort($links, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $links;
    }

    public function defaults(): array
    {
        return [
            SalesTourPlanResource::class,
            VisitResource::class,
            SalesDcrResource::class,
            AccountMasterResource::class,
            EDetailingMaterialResource::class,
            SalesDcrExpenseResource::class,
        ];
    }

    public function selected(User $user, ?array $catalog = null): array
    {
        $catalog ??= $this->catalog();
        $keys = $user->topbar_links ?? $this->defaults();

        return array_values(array_slice(array_intersect(array_unique($keys), array_keys($catalog)), 0, 8));
    }

    public function links(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [];
        }
        $catalog = $this->catalog();

        return array_map(fn (string $key): array => $catalog[$key], $this->selected($user, $catalog));
    }
}

@props([
    'navigation',
])

@php
    $isRtl = __('filament-panels::layout.direction') === 'rtl';
    $isCollapsed = ($_COOKIE['subnav_collapsed'] ?? 'false') === 'true';
@endphp

<aside
    {{
        $attributes
            ->class([
                'fi-page-sub-navigation-sidebar-ctn',
                'fi-subnav-collapsed' => $isCollapsed,
            ])
            ->merge([
                'x-data' => '',
                'x-bind:class' => '{ \'fi-subnav-collapsed\': ! $store.subnav.isOpen }',
            ])
    }}
>
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_SUB_NAVIGATION_SIDEBAR_BEFORE, scopes: $this->getRenderHookScopes()) }}

    <div class="fi-subnav-toggle-row">
        <x-filament::icon-button
            color="gray"
            :icon="$isRtl ? \Filament\Support\Icons\Heroicon::OutlinedChevronLeft : \Filament\Support\Icons\Heroicon::OutlinedChevronRight"
            icon-size="lg"
            :label="__('filament-panels::layout.actions.sidebar.expand.label')"
            x-cloak
            x-show="! $store.subnav.isOpen"
            x-on:click="$store.subnav.open()"
            class="fi-subnav-expand-btn"
        />

        <x-filament::icon-button
            color="gray"
            :icon="$isRtl ? \Filament\Support\Icons\Heroicon::OutlinedChevronRight : \Filament\Support\Icons\Heroicon::OutlinedChevronLeft"
            icon-size="lg"
            :label="__('filament-panels::layout.actions.sidebar.collapse.label')"
            x-cloak
            x-show="$store.subnav.isOpen"
            x-on:click="$store.subnav.close()"
            class="fi-subnav-collapse-btn"
        />
    </div>

    <ul wire:ignore class="fi-page-sub-navigation-sidebar">
        @foreach ($navigation as $navigationGroup)
            @php
                $isNavigationGroupActive = $navigationGroup->isActive();
                $isNavigationGroupCollapsible = $navigationGroup->isCollapsible();
                $navigationGroupIcon = $navigationGroup->getIcon();
                $navigationGroupItems = $navigationGroup->getItems();
                $navigationGroupLabel = $navigationGroup->getLabel();
                $navigationGroupExtraSidebarAttributeBag = $navigationGroup->getExtraSidebarAttributeBag();
            @endphp

            <x-filament-panels::sidebar.group
                :active="$isNavigationGroupActive"
                :collapsible="$isNavigationGroupCollapsible"
                :icon="$navigationGroupIcon"
                :items="$navigationGroupItems"
                :label="$navigationGroupLabel"
                :sidebar-collapsible="false"
                sub-navigation
                :attributes="\Filament\Support\prepare_inherited_attributes($navigationGroupExtraSidebarAttributeBag)"
            />
        @endforeach
    </ul>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_SUB_NAVIGATION_SIDEBAR_AFTER, scopes: $this->getRenderHookScopes()) }}
</aside>

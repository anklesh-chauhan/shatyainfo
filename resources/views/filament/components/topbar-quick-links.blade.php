@auth
    @php
        $quickLinks = app(\App\Support\TopbarLinks::class)->links();
        $menuLabels = [
            'Sales Tour Plan' => 'Tour Plans',
            'Sales DCR' => 'DCR',
            'Account Masters' => 'Customers',
            'E-detailing library' => 'E-detailing',
            'Sales DCR Expenses' => 'Expenses',
        ];
    @endphp
    <style>
        .fi-topbar-start {
            flex-wrap: nowrap;
            align-items: center;
        }

        .it-top-menu {
            display: inline-flex;
            align-items: center;
            gap: 0.125rem;
            min-width: 0;
            margin-inline-start: 1.25rem;
        }

        .it-top-menu-links {
            display: flex;
            align-items: center;
            min-width: 0;
            overflow: hidden;
        }

        .it-top-menu-link {
            appearance: none;
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
            gap: 0.25rem;
            margin: 0;
            padding: 0.375rem 0.625rem;
            border: 0;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            color: #4b5563;
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.25rem;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }

        .it-top-menu-link:hover,
        .it-top-menu-link:focus-visible {
            background: transparent;
            color: #111827;
        }

        .it-top-menu-link:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }

        .it-top-menu-link.is-active {
            border-bottom-color: #2563eb;
            color: #2563eb;
        }

        .it-top-menu .fi-dropdown,
        .it-top-menu .fi-dropdown-trigger {
            display: flex;
            align-items: center;
        }

        .it-top-menu-chevron {
            width: 1rem;
            height: 1rem;
        }

        .dark .it-top-menu-link {
            color: #d1d5db;
        }

        .dark .it-top-menu-link:hover,
        .dark .it-top-menu-link:focus-visible {
            color: #fff;
        }

        .dark .it-top-menu-link.is-active {
            border-bottom-color: #60a5fa;
            color: #60a5fa;
        }
    </style>
    <nav class="it-top-menu" aria-label="Quick links">
        @if (count($quickLinks))
            <div class="it-top-menu-links">
                @foreach ($quickLinks as $link)
                    @php($active = request()->url() === $link['url'] || str_starts_with(request()->url(), rtrim($link['url'], '/').'/'))
                    <a
                        href="{{ $link['url'] }}"
                        title="{{ $link['label'] }}"
                        @if ($active) aria-current="page" @endif
                        @class(['it-top-menu-link', 'is-active' => $active])
                    >{{ $menuLabels[$link['label']] ?? $link['label'] }}</a>
                @endforeach
            </div>
        @endif
        <x-filament::dropdown placement="bottom-start" teleport>
            <x-slot name="trigger">
                <button type="button" class="it-top-menu-link" aria-label="Quick links and configuration">
                    More
                    <x-filament::icon icon="heroicon-m-chevron-down" class="it-top-menu-chevron" />
                </button>
            </x-slot>
            @if (count($quickLinks))
                <x-filament::dropdown.list>
                    @foreach ($quickLinks as $link)
                        <x-filament::dropdown.list.item tag="a" :href="$link['url']">{{ $link['label'] }}</x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item tag="a" :href="\App\Filament\Pages\MyQuickLinks::getUrl()" icon="heroicon-o-cog-6-tooth">Configure quick links</x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </nav>
@endauth

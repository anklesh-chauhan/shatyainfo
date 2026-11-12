<x-filament-panels::page class="data-import-page">
    @include('filament.pages.partials.onboarding-styles')
    <style>
        .data-import-page .setup-card-number { display: none; }
    </style>

    @php
        $companyReady = \App\Models\Tenant::current()?->accountIsConfigured()
            && (app(\App\Services\CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved'] ?? false);
        $moduleMeta = [
            'hqs' => [
                'icon' => 'heroicon-o-building-office-2',
                'description' => 'Import HQ location masters for territories and assignments.',
            ],
            'territories' => [
                'icon' => 'heroicon-o-map',
                'description' => 'Bring in territories, divisions and hierarchy links.',
            ],
            'employees' => [
                'icon' => 'heroicon-o-users',
                'description' => 'Employees, positions, logins and reporting lines.',
            ],
            'accounts' => [
                'icon' => 'heroicon-o-building-storefront',
                'description' => 'Customers, distributors and related account masters.',
            ],
            'field_routes' => [
                'icon' => 'heroicon-o-map-pin',
                'description' => 'Patches, standard fares and historical travel.',
            ],
            'products' => [
                'icon' => 'heroicon-o-cube',
                'description' => 'Products, samples and opening inventory balances.',
            ],
            'hr' => [
                'icon' => 'heroicon-o-calendar-days',
                'description' => 'Shift assignments, holidays and opening leave balances.',
            ],
        ];
        $selectedLabel = $this->importOptions()[$importType] ?? 'Choose a module';
    @endphp

    <div class="space-y-8">
        <div class="overflow-hidden rounded-2xl bg-gradient-to-br from-primary-600 via-primary-600 to-primary-800 p-6 text-white shadow-lg shadow-primary-600/20 dark:from-primary-500 dark:via-primary-600 dark:to-gray-950 sm:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl space-y-3">
                    <p class="text-xs font-semibold tracking-[0.18em] text-white/70 uppercase">
                        Bulk configuration
                    </p>
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                        Import masters with guided templates
                    </h2>
                    <p class="text-sm leading-relaxed text-white/80 sm:text-base">
                        Existing records are matched using the identifiers in each template. Keep those identifiers unchanged when importing again. Blank templates include instructions and examples.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:gap-4">
                    <div class="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm ring-1 ring-white/15">
                        <p class="text-2xl font-semibold tabular-nums">{{ count($this->importOptions()) }}</p>
                        <p class="text-xs text-white/70">Modules</p>
                    </div>
                    <div class="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm ring-1 ring-white/15">
                        <p class="truncate text-sm font-semibold leading-6 sm:text-base">{{ $selectedLabel }}</p>
                        <p class="text-xs text-white/70">Selected</p>
                    </div>
                </div>
            </div>
        </div>

        <x-filament::section
            heading="Module"
            description="Choose what you want to import. Download the blank template, fill your data, then preview before confirming."
            icon="heroicon-o-squares-2x2"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->importOptions() as $value => $label)
                    @php
                        $meta = $moduleMeta[$value] ?? ['icon' => 'heroicon-o-arrow-up-tray', 'description' => ''];
                        $selected = $importType === $value;
                    @endphp
                    <button
                        type="button"
                        wire:key="import-module-{{ $value }}"
                        wire:click="$set('importType', '{{ $value }}')"
                        @class([
                            'flex h-full items-start gap-3 rounded-2xl p-4 text-left ring-1 transition',
                            'bg-primary-50 ring-primary-500 shadow-sm shadow-primary-500/10 dark:bg-primary-400/10 dark:ring-primary-400' => $selected,
                            'bg-white ring-gray-950/5 hover:bg-gray-50 hover:ring-primary-200 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:ring-primary-400/30' => ! $selected,
                        ])
                    >
                        <span
                            @class([
                                'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                'bg-primary-600 text-white dark:bg-primary-500' => $selected,
                                'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400' => ! $selected,
                            ])
                        >
                            <x-filament::icon :icon="$meta['icon']" class="size-5" />
                        </span>
                        <span class="min-w-0 space-y-1">
                            <span class="block text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $label }}
                            </span>
                            <span class="block text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ $meta['description'] }}
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>

            <div class="sr-only">
                <label for="onboarding-import-module">Module</label>
                <select id="onboarding-import-module" wire:model.live="importType">
                    @foreach ($this->importOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </x-filament::section>

        <div class="space-y-6" wire:key="data-import-content-{{ $importType }}">
            @if (! $companyReady)
                <div class="rounded-2xl bg-warning-50 p-5 ring-1 ring-warning-600/10 dark:bg-warning-400/10 dark:ring-warning-400/20">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-warning-800 dark:text-warning-200">
                                Company setup required
                            </p>
                            <p class="text-sm text-warning-700 dark:text-warning-300">
                                Complete company information before importing data.
                            </p>
                        </div>
                        <x-filament::button tag="a" :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')" icon="heroicon-m-clipboard-document-check">
                            Open setup checklist
                        </x-filament::button>
                    </div>
                </div>
            @elseif ($importType === 'employees')
                @include('filament.pages.partials.data-import-employees')
            @elseif (in_array($importType, ['territories', 'accounts'], true))
                <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
                    @livewire(\App\Filament\Pages\Onboarding::class, ['importSection' => $importType], key('data-import-'.$importType))
                </div>
            @elseif ($importType === 'field_routes')
                @if (\App\Filament\Pages\FieldTravelImport::canAccess())
                    <div class="setup-shell rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
                        @livewire(\App\Filament\Pages\FieldTravelImport::class, ['embedded' => true], key('data-import-field-routes'))
                    </div>
                @else
                    <div class="rounded-2xl bg-gray-50 p-5 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                        You need permission to create patches or standard fares to import field routes.
                    </div>
                @endif
            @elseif (in_array($importType, ['hqs', 'products', 'hr'], true))
                <div class="setup-shell rounded-2xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
                    @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => $importType], key('data-import-'.$importType))
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>

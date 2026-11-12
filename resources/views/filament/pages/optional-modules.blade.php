<x-filament-panels::page>
    @php
        $moduleMeta = [
            'inventory' => [
                'icon' => 'heroicon-o-cube',
                'description' => 'Item masters, stock locations, sample issues and SGIP.',
            ],
            'expenses' => [
                'icon' => 'heroicon-o-banknotes',
                'description' => 'Expense types, rules and DCR expense claims.',
            ],
            'hr' => [
                'icon' => 'heroicon-o-calendar-days',
                'description' => 'Shifts, attendance, leave balances and adjustments.',
            ],
            'crm' => [
                'icon' => 'heroicon-o-briefcase',
                'description' => 'Leads, deals, quotes, orders and invoices.',
            ],
        ];
        $activatedCount = collect($modules)->where('enabled', true)->count();
        $readyCount = collect($modules)->where('ready', true)->where('enabled', false)->count();
        $totalModules = count($modules);
        $prepLinks = collect([
            [
                'label' => 'Open configuration imports',
                'icon' => 'heroicon-m-arrow-up-tray',
                'url' => \App\Filament\Pages\DataImport::getUrl(panel: 'admin'),
                'visible' => true,
            ],
            [
                'label' => 'Review user permissions',
                'icon' => 'heroicon-m-users',
                'url' => \App\Filament\Resources\Users\UserResource::getUrl(panel: 'admin'),
                'visible' => \App\Filament\Resources\Users\UserResource::canViewAny(),
            ],
            [
                'label' => 'Review approval flows',
                'icon' => 'heroicon-m-arrows-right-left',
                'url' => \App\Filament\Resources\ApprovalFlows\ApprovalFlowResource::getUrl(panel: 'admin'),
                'visible' => \App\Filament\Resources\ApprovalFlows\ApprovalFlowResource::canViewAny(),
            ],
            [
                'label' => 'Review approval settings',
                'icon' => 'heroicon-m-cog-6-tooth',
                'url' => \App\Filament\Resources\ApprovalSettings\ApprovalSettingResource::getUrl(panel: 'admin'),
                'visible' => \App\Filament\Resources\ApprovalSettings\ApprovalSettingResource::canViewAny(),
            ],
        ])->where('visible', true);
    @endphp

    <div class="space-y-8">
        <div class="overflow-hidden rounded-2xl bg-gradient-to-br from-primary-600 via-primary-600 to-primary-800 p-6 text-white shadow-lg shadow-primary-600/20 dark:from-primary-500 dark:via-primary-600 dark:to-gray-950 sm:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl space-y-3">
                    <p class="text-xs font-semibold tracking-[0.18em] text-white/70 uppercase">
                        Organization readiness
                    </p>
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                        Activate modules when your company is ready
                    </h2>
                    <p class="text-sm leading-relaxed text-white/80 sm:text-base">
                        Prepare additional modules when your company needs them. Review employee permissions and approval routes before activation.
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-3 sm:gap-4">
                    <div class="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm ring-1 ring-white/15">
                        <p class="text-2xl font-semibold tabular-nums">{{ $activatedCount }}</p>
                        <p class="text-xs text-white/70">Activated</p>
                    </div>
                    <div class="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm ring-1 ring-white/15">
                        <p class="text-2xl font-semibold tabular-nums">{{ $readyCount }}</p>
                        <p class="text-xs text-white/70">Ready now</p>
                    </div>
                    <div class="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm ring-1 ring-white/15">
                        <p class="text-2xl font-semibold tabular-nums">{{ $totalModules }}</p>
                        <p class="text-xs text-white/70">Available</p>
                    </div>
                </div>
            </div>
        </div>

        <x-filament::section
            heading="Before you activate"
            description="Confirm imports, permissions and approval routes so modules open cleanly for your team."
            icon="heroicon-o-clipboard-document-check"
        >
            <div class="flex flex-wrap gap-3">
                @foreach ($prepLinks as $link)
                    <a
                        href="{{ $link['url'] }}"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-950/5 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-200 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-primary-400/10 dark:hover:text-primary-300 dark:hover:ring-primary-400/30"
                    >
                        <x-filament::icon :icon="$link['icon']" class="size-4 text-primary-600 dark:text-primary-400" />
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </x-filament::section>

        @if ($errors->any())
            <div
                class="rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-600/10 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/20"
                role="alert"
            >
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            @foreach ($modules as $key => $module)
                @php
                    $completedChecks = count(array_filter($module['checks']));
                    $totalChecks = count($module['checks']);
                    $progress = $totalChecks > 0 ? (int) round(($completedChecks / $totalChecks) * 100) : 0;
                    $meta = $moduleMeta[$key] ?? ['icon' => 'heroicon-o-puzzle-piece', 'description' => ''];
                    $activatedAt = $module['activation']['activated_at'] ?? null;
                @endphp

                <div
                    wire:key="module-{{ $key }}"
                    @class([
                        'flex h-full flex-col gap-5 rounded-2xl bg-white p-5 ring-1 transition dark:bg-white/5 sm:p-6',
                        'ring-success-500/30 shadow-sm shadow-success-500/10' => $module['enabled'],
                        'ring-primary-500/25 shadow-sm shadow-primary-500/10' => ! $module['enabled'] && $module['ready'],
                        'ring-gray-950/5 dark:ring-white/10' => ! $module['enabled'] && ! $module['ready'],
                    ])
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span
                                @class([
                                    'flex size-11 shrink-0 items-center justify-center rounded-xl',
                                    'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400' => $module['enabled'],
                                    'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400' => ! $module['enabled'] && $module['ready'],
                                    'bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $module['enabled'] && ! $module['ready'],
                                ])
                            >
                                <x-filament::icon :icon="$meta['icon']" class="size-5" />
                            </span>

                            <div class="space-y-1">
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                                    {{ $module['label'] }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $meta['description'] }}
                                </p>
                            </div>
                        </div>

                        @if ($module['enabled'])
                            <x-filament::badge color="success" size="sm">
                                <span class="sr-only">Activated</span>
                                <span aria-hidden="true">Active</span>
                            </x-filament::badge>
                        @elseif ($module['ready'])
                            <x-filament::badge color="primary" size="sm">
                                <span class="sr-only">Preparing</span>
                                <span aria-hidden="true">Ready</span>
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray" size="sm">
                                <span class="sr-only">Preparing</span>
                                <span aria-hidden="true">Pending</span>
                            </x-filament::badge>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <p class="font-medium text-gray-700 dark:text-gray-200">
                                {{ $completedChecks }} of {{ $totalChecks }} configuration checks complete
                            </p>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $progress }}%</span>
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $progress }}"
                            aria-label="{{ $module['label'] }} configuration progress"
                        >
                            <div
                                @class([
                                    'h-full rounded-full transition-all duration-500',
                                    'bg-success-500' => $module['enabled'] || $progress === 100,
                                    'bg-primary-500' => ! $module['enabled'] && $progress > 0 && $progress < 100,
                                    'bg-gray-300 dark:bg-gray-600' => $progress === 0,
                                ])
                                style="width: {{ $progress }}%"
                            ></div>
                        </div>
                    </div>

                    <ul class="space-y-2.5">
                        @foreach ($module['checks'] as $label => $complete)
                            <li class="flex items-start gap-2.5 text-sm">
                                <span
                                    @class([
                                        'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                                        'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400' => $complete,
                                        'bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500' => ! $complete,
                                    ])
                                    aria-hidden="true"
                                >
                                    @if ($complete)
                                        <x-filament::icon icon="heroicon-m-check" class="size-3.5" />
                                    @else
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                    @endif
                                </span>
                                <span @class([
                                    'text-gray-700 dark:text-gray-200' => $complete,
                                    'text-gray-500 dark:text-gray-400' => ! $complete,
                                ])>
                                    <span class="sr-only">{{ $complete ? '✓' : '○' }}</span>
                                    {{ $label }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-auto space-y-4 border-t border-gray-100 pt-4 dark:border-white/10">
                        @if (! $module['enabled'])
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl bg-gray-50 p-3 text-sm text-gray-600 transition hover:bg-gray-100 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10">
                                <input
                                    type="checkbox"
                                    wire:model.live="reviewed.{{ $key }}"
                                    class="mt-0.5 size-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                                >
                                <span>
                                    I reviewed employee permissions and confirmed approval routes or the no-approval choice in the existing module settings.
                                </span>
                            </label>

                            <x-filament::button
                                wire:click="activate('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="activate('{{ $key }}')"
                                :disabled="! $module['ready']"
                                icon="heroicon-m-bolt"
                                class="w-full justify-center sm:w-auto"
                            >
                                <span wire:loading.remove wire:target="activate('{{ $key }}')">Activate module</span>
                                <span wire:loading wire:target="activate('{{ $key }}')">Activating…</span>
                            </x-filament::button>
                        @else
                            <div class="flex items-center gap-3 rounded-xl bg-success-50 px-3 py-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-300">
                                <x-filament::icon icon="heroicon-m-check-badge" class="size-5 shrink-0" />
                                <div>
                                    <p class="font-medium">Module is live for your company</p>
                                    @if ($activatedAt)
                                        <p class="text-success-700/80 dark:text-success-300/80">
                                            Activated {{ \Illuminate\Support\Carbon::parse($activatedAt)->timezone(config('app.timezone'))->format(app(\App\Services\DateFormatService::class)->dateTimeFormat()) }}
                                        </p>
                                    @else
                                        <p>Activated</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

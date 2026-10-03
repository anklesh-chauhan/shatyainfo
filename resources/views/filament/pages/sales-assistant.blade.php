<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Today’s priorities</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $priorities['summary'] ?? '' }}</p>
            <ul class="mt-4 space-y-3">
                @forelse ($priorities['items'] ?? [] as $item)
                    <li>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $item['title'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $item['reason'] }}</p>
                    </li>
                @empty
                    <li class="text-sm text-gray-600 dark:text-gray-300">No priority calls for today.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Day route</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $route['summary'] ?? '' }}</p>
            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-200">
                @foreach ($route['stops'] ?? [] as $stop)
                    <li>{{ $stop['name'] }} <span class="text-gray-500">{{ $stop['patch_name'] }}</span></li>
                @endforeach
            </ol>
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Next month tour-plan draft</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $tourDraft['summary'] ?? '' }}</p>
            <ul class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                @foreach ($tourDraft['days'] ?? [] as $day)
                    <li>{{ $day['date'] }} — {{ implode(', ', $day['patch_names'] ?? []) }}</li>
                @endforeach
            </ul>
            @if (($tourDraft['uncovered_accounts'] ?? []) !== [])
                <p class="mt-4 text-sm font-medium text-gray-950 dark:text-white">Missed last month</p>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                    @foreach ($tourDraft['uncovered_accounts'] as $account)
                        <li>{{ $account['name'] }} — {{ $account['reason'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Assistant features</h2>
            <ul class="mt-4 space-y-3">
                @foreach ($features as $feature)
                    <li>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $feature['order'] }}. {{ $feature['label'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $feature['where_to_use'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-filament-panels::page>

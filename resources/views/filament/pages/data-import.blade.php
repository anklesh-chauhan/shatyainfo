<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import">
                <span wire:loading.remove wire:target="import">Import file</span>
                <span wire:loading wire:target="import">Importing…</span>
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="downloadExample" wire:loading.attr="disabled">
                Download template
            </x-filament::button>
        </div>
    </form>

    @php($module = $this->selectedModule())

    @if ($module)
        <section class="mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $module->label }} columns</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @if ($module->matchAttribute)
                    Existing records match on {{ str($module->matchAttribute)->headline() }}. A blank cell leaves the current value in place.
                @else
                    Each row is added as a new record. A blank cell is skipped.
                @endif
                @if ($module->modelClass === \App\Models\Employee::class)
                    Fill the Employees sheet and follow the Instructions and Examples tabs. Each employee needs exactly one primary position. Reporting links use position codes; an expected manager employee code is optional.
                    The entire employee file is saved together. Any error saves nothing. Blank cells preserve existing details; use clear or remove for explicit changes. Employee records are imported without sending login invitations.
                @else
                    Related columns must match an existing record, usually by name or code. Import those lists first.
                @endif
            </p>
            <ul class="mt-4 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                @foreach ($module->columns as $column)
                    <li>
                        <span class="font-medium text-gray-950 dark:text-white">{{ $column->header }}</span>
                        — {{ $column->label }}
                        @if ($column->required)
                            <span class="text-gray-500">(required)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($hasResult)
        <section class="mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Last import</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ $result['created'] }} created, {{ $result['updated'] }} updated, {{ count($result['failed']) }} failed.
            </p>

            @if ($result['failed'] !== [])
                <ul class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                    @foreach (array_slice($result['failed'], 0, 50) as $failure)
                        <li>Row {{ $failure['row'] }}: {{ $failure['message'] }}</li>
                    @endforeach
                </ul>
                @if (count($result['failed']) > 50)
                    <p class="mt-3 text-sm text-gray-500">Showing the first 50 failed rows.</p>
                @endif
            @endif
            @if (!empty($result['hierarchy']))
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr><th class="p-2">Employee</th><th class="p-2">Primary position</th><th class="p-2">Reports to position</th><th class="p-2">Manager</th><th class="p-2">Status</th></tr></thead>
                        <tbody>
                            @foreach ($result['hierarchy'] as $entry)
                                <tr><td class="p-2">{{ $entry['employee'] }}</td><td class="p-2">{{ $entry['position'] }}</td><td class="p-2">{{ $entry['reports_to_position'] }}</td><td class="p-2">{{ $entry['manager'] }}</td><td class="p-2">{{ $entry['status'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-filament-panels::page>

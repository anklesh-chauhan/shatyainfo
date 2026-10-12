<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import">
                <span wire:loading.remove wire:target="import">Import file</span>
                <span wire:loading wire:target="import">Importing…</span>
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="downloadExample" wire:loading.attr="disabled">
                Download example CSV
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
                Related columns must match an existing record, usually by name or code. Import those lists first.
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
        </section>
    @endif
</x-filament-panels::page>

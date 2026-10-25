    @php
        $module = $this->selectedModule();
        $isEmployeeImport = $module?->modelClass === \App\Models\Employee::class;
        $employeeGuide = $isEmployeeImport ? app(\App\Services\DataImport\EmployeeImportTemplate::class) : null;
    @endphp

    @if ($employeeGuide)
        @if (\App\Filament\Pages\Onboarding::canAccess())
            <div>
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')">Back to setup checklist</x-filament::button>
            </div>
        @endif
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">How to import employees</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Download the template below, then follow these instructions. These are the same rules included in the Excel template.</p>
            <dl class="mt-4 space-y-4 text-sm">
                @foreach (array_slice($employeeGuide->instructions(), 0, 4) as [$heading, $instruction])
                    <div>
                        <dt class="font-medium text-gray-950 dark:text-white">{{ $heading }}</dt>
                        <dd class="mt-1 text-gray-600 dark:text-gray-300">{{ $instruction }}</dd>
                    </div>
                @endforeach
            </dl>

            <details class="mt-6">
                <summary class="cursor-pointer font-semibold text-gray-950 dark:text-white">Reporting hierarchy and update rules</summary>
                <dl class="mt-4 space-y-4 text-sm">
                    @foreach (array_slice($employeeGuide->instructions(), 4) as [$heading, $instruction])
                        <div>
                            <dt class="font-medium text-gray-950 dark:text-white">{{ $heading }}</dt>
                            <dd class="mt-1 text-gray-600 dark:text-gray-300">{{ $instruction }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>

            <details class="mt-6">
                <summary class="cursor-pointer font-semibold text-gray-950 dark:text-white">Example reporting hierarchy</summary>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Fictitious examples from the template. Replace them with your team. Managers may appear anywhere in the file.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-700 dark:text-gray-200">
                        <thead><tr><th scope="col" class="p-2">Employee code</th><th scope="col" class="p-2">Position code</th><th scope="col" class="p-2">Primary</th><th scope="col" class="p-2">Reports to position</th><th scope="col" class="p-2">Expected manager code</th></tr></thead>
                        <tbody>
                            @foreach ($employeeGuide->examples() as $example)
                                <tr>
                                    <td class="p-2">{{ $example['employee_id'] }}</td>
                                    <td class="p-2">{{ $example['position_code'] }}</td>
                                    <td class="p-2">{{ $example['is_primary_position'] }}</td>
                                    <td class="p-2">{{ $example['reports_to_position_code'] ?? 'Top-level' }}</td>
                                    <td class="p-2">{{ $example['reports_to_employee_code'] ?? 'None' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    @endif

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

    @if ($module)
        <section class="mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $module->label }} columns</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @if ($module->matchAttribute)
                    Existing records match on {{ str($module->matchAttribute)->headline() }}. A blank cell leaves the current value in place.
                @else
                    Each row is added as a new record. A blank cell is skipped.
                @endif
                @if ($isEmployeeImport)
                    Keep all template headers. Required personal details must be supplied for new employees; blank cells preserve those details for existing employees.
                @else
                    Related columns must match an existing record, usually by name or code. Import those lists first.
                @endif
            </p>
            <ul class="mt-4 space-y-4 text-sm text-gray-700 dark:text-gray-200">
                @foreach ($module->columns as $column)
                    <li>
                        <span class="font-medium text-gray-950 dark:text-white">{{ $column->header }}</span>
                        — {{ $column->label }}
                        @if ($column->required)
                            <span class="text-gray-500">(required)</span>
                        @endif
                        @if ($employeeGuide)
                            <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $employeeGuide->columnInstructions()[$column->header] ?? '' }}</p>
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

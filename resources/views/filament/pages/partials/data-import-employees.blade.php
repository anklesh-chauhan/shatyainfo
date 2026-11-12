    @php
        $module = $this->selectedModule();
        $isEmployeeImport = $module?->modelClass === \App\Models\Employee::class;
        $employeeGuide = $isEmployeeImport ? app(\App\Services\DataImport\EmployeeImportTemplate::class) : null;
    @endphp

    @if ($employeeGuide && ! $embedded)
        @if (! $embedded && \App\Filament\Pages\Onboarding::canAccess())
            <div>
                <x-filament::button tag="a" color="gray" icon="heroicon-m-arrow-left" :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')">Back to setup checklist</x-filament::button>
            </div>
        @endif

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-book-open" class="size-5" />
                </span>
                <div class="space-y-1">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">How to import employees</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Download the template below, then follow these instructions. These are the same rules included in the Excel template.</p>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach (array_slice($employeeGuide->instructions(), 0, 4) as [$heading, $instruction])
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-sm font-medium text-gray-950 dark:text-white">{{ $heading }}</dt>
                        <dd class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $instruction }}</dd>
                    </div>
                @endforeach
            </dl>

            <details class="mt-5 rounded-xl border border-gray-100 open:bg-gray-50 dark:border-white/10 dark:open:bg-white/5">
                <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-950 dark:text-white">Reporting hierarchy and update rules</summary>
                <dl class="space-y-4 border-t border-gray-100 px-4 py-4 text-sm dark:border-white/10">
                    @foreach (array_slice($employeeGuide->instructions(), 4) as [$heading, $instruction])
                        <div>
                            <dt class="font-medium text-gray-950 dark:text-white">{{ $heading }}</dt>
                            <dd class="mt-1 text-gray-600 dark:text-gray-300">{{ $instruction }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>

            <details class="mt-3 rounded-xl border border-gray-100 open:bg-gray-50 dark:border-white/10 dark:open:bg-white/5">
                <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-950 dark:text-white">Example reporting hierarchy</summary>
                <div class="border-t border-gray-100 px-4 py-4 dark:border-white/10">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Fictitious examples from the template. Replace them with your team. Managers may appear anywhere in the file.</p>
                    <div class="mt-3 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                        <table class="w-full text-left text-sm text-gray-700 dark:text-gray-200">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th scope="col" class="p-2.5 font-medium">Employee code</th>
                                    <th scope="col" class="p-2.5 font-medium">Position code</th>
                                    <th scope="col" class="p-2.5 font-medium">Primary</th>
                                    <th scope="col" class="p-2.5 font-medium">Reports to position</th>
                                    <th scope="col" class="p-2.5 font-medium">Expected manager code</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($employeeGuide->examples() as $example)
                                    <tr class="border-t border-gray-100 dark:border-white/10">
                                        <td class="p-2.5">{{ $example['employee_id'] }}</td>
                                        <td class="p-2.5">{{ $example['position_code'] }}</td>
                                        <td class="p-2.5">{{ $example['is_primary_position'] }}</td>
                                        <td class="p-2.5">{{ $example['reports_to_position_code'] ?? 'Top-level' }}</td>
                                        <td class="p-2.5">{{ $example['reports_to_employee_code'] ?? 'None' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
        </section>
    @endif

    @if ($embedded)
        @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewEmployees', 'fileProperty' => 'data.file.0', 'errorProperty' => 'data.file', 'inputId' => 'employee-file', 'accept' => '.xlsx,.csv', 'fileHelp' => 'Excel or CSV · Maximum 20 MB.'])
    @else
    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
        <div class="mb-5 flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                <x-filament::icon icon="heroicon-o-arrow-up-tray" class="size-5" />
            </span>
            <div class="space-y-1">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Upload and preview</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Choose your filled template, preview the rows, then confirm only when the file looks right.</p>
            </div>
        </div>

        <form wire:submit="previewEmployees" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap gap-3">
                <x-filament::button type="submit" icon="heroicon-m-eye" wire:loading.attr="disabled" wire:target="previewEmployees">
                    <span wire:loading.remove wire:target="previewEmployees">Preview file</span>
                    <span wire:loading wire:target="previewEmployees">Reading…</span>
                </x-filament::button>

                <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="downloadExample" wire:loading.attr="disabled">
                    Download template
                </x-filament::button>
            </div>
        </form>
    </section>
    @endif

    @if ($employeePreview !== null)
        <section x-show="$wire.employeePreview !== null && Object.keys($wire.data.file ?? {}).length > 0" class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Review before importing</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $employeePreview['count'] }} employee rows · {{ count($employeePreview['errors']) }} file errors. Nothing has been saved.</p>
                </div>
                @if ($employeePreview['errors'] === [])
                    <x-filament::badge color="success" size="sm">Ready to confirm</x-filament::badge>
                @else
                    <x-filament::badge color="danger" size="sm">Fix file errors</x-filament::badge>
                @endif
            </div>
            @if ($employeePreview['errors'] !== [])
                <ul class="mt-4 space-y-2 rounded-xl bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">@foreach ($employeePreview['errors'] as $error)<li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>@endforeach</ul>
            @else
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10"><table class="w-full text-left text-sm"><thead class="bg-gray-50 dark:bg-white/5"><tr><th class="p-2.5 font-medium">Row</th><th class="p-2.5 font-medium">Employee code</th><th class="p-2.5 font-medium">Name</th><th class="p-2.5 font-medium">Position code</th><th class="p-2.5 font-medium">Reports to position</th><th class="p-2.5 font-medium">Action</th></tr></thead><tbody>
                    @foreach ($employeePreview['rows'] as $row)
                        <tr class="border-t border-gray-100 dark:border-white/10"><td class="p-2.5">{{ $row['line'] }}</td><td class="p-2.5">{{ $row['employee'] }}</td><td class="p-2.5">{{ $row['name'] }}</td><td class="p-2.5">{{ $row['position'] }}</td><td class="p-2.5">{{ $row['manager'] ?: '—' }}</td><td class="p-2.5">{{ $row['action'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($employeePreview['count'] > count($employeePreview['rows']))<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Showing the first {{ count($employeePreview['rows']) }} rows.</p>@endif
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Detailed employee and reporting validation runs when you confirm. If it fails, no rows are saved.</p>
                <x-filament::button class="mt-4" icon="heroicon-m-check" wire:click="import" wire:loading.attr="disabled" wire:target="import">Confirm import</x-filament::button>
            @endif
        </section>
    @endif

    @if ($module && ! $embedded)
        <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-table-cells" class="size-5" />
                </span>
                <div class="space-y-1">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $module->label }} columns</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
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
                </div>
            </div>
            <ul class="mt-5 divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($module->columns as $column)
                    <li class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:gap-4">
                        <div class="min-w-0 sm:w-56">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $column->header }}</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $column->label }}
                                @if ($column->required)
                                    <span>(required)</span>
                                @endif
                            </p>
                        </div>
                        @if ($employeeGuide)
                            <p class="flex-1 text-sm text-gray-600 dark:text-gray-300">{{ $employeeGuide->columnInstructions()[$column->header] ?? '' }}</p>
                        @else
                            <p class="flex-1 text-sm text-gray-600 dark:text-gray-300">{{ $column->label }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($hasResult && (! $embedded || $result['failed'] !== []))
        <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:p-6">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $embedded ? 'Rows to fix' : 'Last import' }}</h2>
            @unless ($embedded)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $result['created'] }} created, {{ $result['updated'] }} updated, {{ count($result['failed']) }} failed.
                </p>
            @endunless

            @if ($result['failed'] !== [])
                <ul class="mt-4 space-y-2 rounded-xl bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">
                    @foreach (array_slice($result['failed'], 0, 50) as $failure)
                        <li>Row {{ $failure['row'] }}: {{ $failure['message'] }}</li>
                    @endforeach
                </ul>
                @if (count($result['failed']) > 50)
                    <p class="mt-3 text-sm text-gray-500">Showing the first 50 failed rows.</p>
                @endif
            @endif
            @if (! $embedded && ! empty($result['hierarchy']))
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-white/5"><tr><th class="p-2.5 font-medium">Employee</th><th class="p-2.5 font-medium">Primary position</th><th class="p-2.5 font-medium">Reports to position</th><th class="p-2.5 font-medium">Manager</th><th class="p-2.5 font-medium">Status</th></tr></thead>
                        <tbody>
                            @foreach ($result['hierarchy'] as $entry)
                                <tr class="border-t border-gray-100 dark:border-white/10"><td class="p-2.5">{{ $entry['employee'] }}</td><td class="p-2.5">{{ $entry['position'] }}</td><td class="p-2.5">{{ $entry['reports_to_position'] }}</td><td class="p-2.5">{{ $entry['manager'] }}</td><td class="p-2.5">{{ $entry['status'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

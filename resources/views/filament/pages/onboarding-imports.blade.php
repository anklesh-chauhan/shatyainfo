<x-filament-panels::page>
    <x-filament::link :href="\App\Filament\Pages\Onboarding::getUrl(['section' => 'field-travel'], panel: 'admin')">
        Return to Setup Checklist
    </x-filament::link>

    <x-filament::section heading="Choose an optional import" description="Download a template, fill the data sheet, preview your rows, then confirm. You can return to the checklist and complete these imports later.">
        <label for="setup-import-module">Import type</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="setup-import-module" wire:model.live="module">
                @foreach (\App\Services\DataImport\OptionalOnboardingImport::MODULES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <p>{{ \App\Services\DataImport\OptionalOnboardingTemplate::HELP[$module] ?? '' }}</p>
        <x-filament::button color="gray" wire:click="downloadTemplate" wire:loading.attr="disabled">
            Download Excel template
        </x-filament::button>
    </x-filament::section>

    <x-filament::section heading="Upload and preview">
        <label for="setup-import-file">Excel or CSV file (up to 500 rows, 2 MB)</label>
        <input id="setup-import-file" type="file" accept=".xlsx,.csv" wire:model="file" wire:key="setup-import-file-{{ $module }}">
        @foreach ($errors->all() as $message)
            <p role="alert">{{ $message }}</p>
        @endforeach
        <x-filament::button wire:click="previewFile" wire:loading.attr="disabled">
            Preview file
        </x-filament::button>
        <p wire:loading wire:target="file,previewFile,importReviewed">Processing your file…</p>
    </x-filament::section>

    @if ($preview !== [])
        <x-filament::section heading="Review before importing">
            <p>{{ count($preview['plans']) }} valid rows; {{ count($preview['errors']) }} errors. Nothing has been saved.</p>
            @if ($preview['errors'] !== [])
                <p role="alert">Correct all errors and upload the file again.</p>
                <x-filament::link tag="button" wire:click="downloadErrors">Download row errors</x-filament::link>
                <ul>
                    @foreach (array_slice($preview['errors'], 0, 20) as $error)
                        <li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            @endif
            <div style="overflow-x: auto; max-height: 28rem">
                <table class="fi-ta-table">
                    <thead><tr><th>Row</th><th>Record</th><th>Action</th><th>Uploaded values</th></tr></thead>
                    <tbody>
                        @foreach ($preview['plans'] as $plan)
                            <tr>
                                <td>{{ $plan['line'] }}</td>
                                <td>{{ $plan['key'] }}</td>
                                <td>{{ ucfirst($plan['action']) }}</td>
                                <td>
                                    <details>
                                        <summary>View values</summary>
                                        <dl>
                                            @foreach ($rows as $row)
                                                @if ($row['line'] === $plan['line'])
                                                    @foreach ($row['data'] as $column => $value)
                                                        <dt>{{ str($column)->headline() }}</dt><dd>{{ $value === '' ? '—' : $value }}</dd>
                                                    @endforeach
                                                @endif
                                            @endforeach
                                        </dl>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($preview['errors'] === [] && $preview['plans'] !== [])
                <x-filament::button wire:click="importReviewed" wire:loading.attr="disabled">Confirm import</x-filament::button>
            @endif
        </x-filament::section>
    @endif

    @if ($result !== [])
        <x-filament::section heading="Import complete">
            <p role="status">{{ $result['created'] }} created, {{ $result['updated'] }} updated, {{ $result['unchanged'] }} unchanged.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>

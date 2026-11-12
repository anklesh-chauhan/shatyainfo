@php
    $importHeading = match ($group) { 'hqs' => 'HQ Locations', 'products' => 'Products & Samples', default => 'HR Setup' };
    $importDescription = match ($group) { 'hqs' => 'Import HQ locations before assigning them to MRs.', 'products' => 'Import products, samples and opening inventory.', default => 'Import shift assignments, holidays and opening leave balances.' };
    $fileSubject = match ($module) { 'hqs' => 'HQ', 'products' => 'product', 'shifts' => 'shift assignment', 'holidays' => 'holiday', 'leave_openings' => 'opening leave balance', 'inventory_openings' => 'opening inventory' };
    $fileInstructions = match ($module) {
        'hqs' => 'Enter a unique location_code and name. Keep the code unchanged when updating an HQ.',
        'products' => 'Use a unique SKU per item. Choose brands, units and tax names from References. Blank optional cells keep existing values.',
        'shifts' => 'Create employees and shifts first. Use HR employee codes and dates in YYYY-MM-DD format. Assignment periods must not overlap.',
        'holidays' => 'Use dates in YYYY-MM-DD format and existing names from References. Leave country, state and location blank for a company-wide holiday.',
        'leave_openings' => 'Create employees and leave types first. Use their codes from References. Opening balances cannot replace existing leave or payroll activity.',
        'inventory_openings' => 'Create items and stock locations first. Enter quantity, unit cost and a date on or before today. Existing stock or movements prevent an opening import.',
    };
@endphp
<section class="setup-import-card" aria-label="{{ $importHeading }}">
    <div class="setup-card-heading"><span class="setup-card-number">↑</span><div><h3>{{ $importHeading }}</h3><p>{{ $importDescription }}</p></div></div>
    <div class="setup-field">
        <label for="import-module-{{ $group }}">Import type</label>
        <x-filament::input.wrapper><x-filament::input.select id="import-module-{{ $group }}" wire:model.live="module">
            @foreach ($this->moduleOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper>
    </div>
    <x-filament::button color="gray" wire:click="downloadTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <details class="setup-optional">
        <summary>How to prepare your {{ $fileSubject }} file</summary>
        <p>{{ $fileInstructions }}</p>
        @if ($module === 'hqs')
            <p>{{ app(\App\Services\CompanyLaunchService::class)->requiresTerritoryLinkedHq() ? 'After import, enter these location codes in the territory file’s hq_codes column, or link them in Location Masters.' : 'This file creates locations only. Assign HQs separately in HQ & Customer Assignments.' }}</p>
        @endif
        <p>Fill the data sheet only. See Instructions and References in the template for field details. Preview the file, fix any errors, then select Confirm import.</p>
    </details>
    @foreach ($errors->all() as $message)<p class="setup-error" role="alert">{{ $message }}</p>@endforeach
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewFile', 'fileProperty' => 'file', 'inputId' => 'optional-file-'.$group.'-'.$module, 'accept' => '.xlsx,.csv', 'fileHelp' => 'Excel or CSV · Up to 500 rows · Maximum 2 MB.'])
    @if ($result !== [])<p class="setup-success" role="status">{{ $result['created'] }} created, {{ $result['updated'] }} updated, {{ $result['unchanged'] }} unchanged.</p>@endif
    @if ($preview !== [])
        <div class="setup-note">
            <h4>Review before importing</h4>
            <p>{{ count($preview['plans']) }} valid rows · {{ count($preview['errors']) }} errors. Nothing has been saved.</p>
            @if ($preview['errors'] !== [])
                <p class="setup-error" role="alert">Correct all errors and upload the file again.</p>
                <button type="button" class="setup-text-button" wire:click="downloadErrors">Download row errors (CSV)</button>
                <ul>@foreach ($preview['errors'] as $error)<li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>@endforeach</ul>
            @endif
            <div style="overflow:auto;max-height:320px"><table class="setup-territories"><thead><tr><th>Row</th><th>Record</th><th>Action</th><th>Details</th></tr></thead><tbody>
                @foreach ($preview['plans'] as $plan)
                    <tr><td>{{ $plan['line'] }}</td><td>{{ $plan['key'] }}</td><td>{{ ucfirst($plan['action']) }}</td><td><details><summary>Review supplied fields</summary><dl>
                        @foreach ($rows as $row) @if ($row['line'] === $plan['line'])
                            @foreach ($row['data'] as $column => $value)<dt>{{ str($column)->headline() }}</dt><dd>{{ $value === '' ? '—' : $value }}</dd>@endforeach
                        @endif @endforeach
                    </dl></details></td></tr>
                @endforeach
            </tbody></table></div>
            @if ($preview['errors'] === [] && $preview['plans'] !== [])<x-filament::button wire:click="importReviewed" wire:loading.attr="disabled">Confirm import</x-filament::button>@endif
        </div>
    @endif
</section>

<section class="setup-import-card" aria-label="{{ $group === 'products' ? 'Products and samples setup' : 'HR setup imports' }}">
    <div class="setup-card-heading"><span class="setup-card-number">{{ $group === 'products' ? 3 : 4 }}</span><div><h3>{{ $group === 'products' ? 'Products & Samples' : 'HR Setup' }}</h3><p>{{ $group === 'products' ? 'Import products, samples and opening inventory.' : 'Import shift assignments, holidays and opening leave balances.' }}</p></div></div>
    <div class="setup-card-stats"><span>Excel or CSV · Up to 500 rows</span></div>
    <div class="setup-field">
        <label for="import-module-{{ $group }}">Import type</label>
        <x-filament::input.wrapper><x-filament::input.select id="import-module-{{ $group }}" wire:model.live="module">
            @foreach ($this->moduleOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper>
    </div>
    <x-filament::button color="gray" wire:click="downloadTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <details class="setup-optional"><summary>Instructions and examples</summary><p>{{ \App\Services\DataImport\OptionalOnboardingTemplate::HELP[$module] ?? '' }}</p><p>Fill the data sheet only. Instructions, Examples and References help you prepare the file. Preview does not save records; correct all errors before confirming.</p></details>
    @foreach ($errors->all() as $message)<p class="setup-error" role="alert">{{ $message }}</p>@endforeach
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewFile', 'fileProperty' => 'file', 'inputId' => 'optional-file-'.$group.'-'.$module, 'accept' => '.xlsx,.csv', 'fileHelp' => '.xlsx or .csv · Maximum 2 MB.'])
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

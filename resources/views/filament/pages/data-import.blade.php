<x-filament-panels::page class="data-import-page">
    @include('filament.pages.partials.onboarding-styles')
    <style>
        .data-import-page .setup-card-number { display: none; }
    </style>
    <x-filament::section>
        <p>Existing records are matched using the identifiers in each template. Keep those identifiers unchanged when importing again. Blank templates include instructions and examples.</p>
        <div class="setup-field" style="margin-top:20px">
            <label for="onboarding-import-module">Module</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="onboarding-import-module" wire:model.live="importType">
                    @foreach ($this->importOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    @if (! \App\Models\Tenant::current()?->accountIsConfigured() || ! (app(\App\Services\CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved'] ?? false))
        <x-filament::section>
            <p>Complete company information before importing data.</p>
            <x-filament::button tag="a" :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')">Open setup checklist</x-filament::button>
        </x-filament::section>
    @elseif ($importType === 'employees')
        @include('filament.pages.partials.data-import-employees')
    @elseif (in_array($importType, ['territories', 'accounts'], true))
        @livewire(\App\Filament\Pages\Onboarding::class, ['importSection' => $importType], key('data-import-'.$importType))
    @elseif ($importType === 'field_routes')
        @if (\App\Filament\Pages\FieldTravelImport::canAccess())
            <div class="setup-shell">@livewire(\App\Filament\Pages\FieldTravelImport::class, ['embedded' => true], key('data-import-field-routes'))</div>
        @else
            <p>You need permission to create patches or standard fares to import field routes.</p>
        @endif
    @elseif (in_array($importType, ['products', 'hr'], true))
        <div class="setup-shell">@livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => $importType], key('data-import-'.$importType))</div>
    @endif
</x-filament-panels::page>

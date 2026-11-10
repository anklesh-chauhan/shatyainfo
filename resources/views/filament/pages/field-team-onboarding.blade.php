<x-filament-panels::page>
    @if (\App\Filament\Pages\Onboarding::canAccess())
        <x-filament::link :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')">Return to company setup</x-filament::link>
    @endif
    @if (\App\Filament\Pages\DataImport::canAccess())
        <x-filament::link :href="\App\Filament\Pages\DataImport::getUrl(['module' => 'hqs'], panel: 'admin')">Import HQ locations from Excel or CSV</x-filament::link>
    @endif
    <form wire:submit="save">
        {{ $this->form }}
        <x-filament::button type="submit">Save assignments</x-filament::button>
    </form>
</x-filament-panels::page>

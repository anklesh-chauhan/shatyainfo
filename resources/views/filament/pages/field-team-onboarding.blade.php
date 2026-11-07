<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <x-filament::button type="submit">Save HQ and assignments</x-filament::button>
    </form>
</x-filament-panels::page>

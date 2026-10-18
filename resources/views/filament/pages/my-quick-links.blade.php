<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit">Save quick links</x-filament::button>
            <x-filament::button color="gray" wire:click="resetDefaults">Restore pharma defaults</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save,testConnection">
                Save configuration
            </x-filament::button>
            <x-filament::button color="gray" wire:click="testConnection" wire:loading.attr="disabled" wire:target="save,testConnection">
                Test connection
            </x-filament::button>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Connection tests use sample text only and do not save changes. Cloud providers may charge for the test.
        </p>
    </form>
</x-filament-panels::page>

<x-filament-widgets::widget class="fi-wi-stats-overview">
    @if ($canSwitchWorkspace)
        <div role="group" aria-label="Field workspace">
            <x-filament::button wire:click="switchWorkspace('personal')" :aria-pressed="$workspace === 'personal' ? 'true' : 'false'" :color="$workspace === 'personal' ? 'primary' : 'gray'">
                Personal work
            </x-filament::button>
            <x-filament::button wire:click="switchWorkspace('team')" :aria-pressed="$workspace === 'team' ? 'true' : 'false'" :color="$workspace === 'team' ? 'primary' : 'gray'">
                Team management
            </x-filament::button>
        </div>
    @endif

    {{ $this->content }}
</x-filament-widgets::widget>

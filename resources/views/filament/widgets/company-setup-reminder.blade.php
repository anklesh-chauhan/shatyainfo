<x-filament-widgets::widget>
    @if (\App\Filament\Widgets\CompanySetupReminder::canView())
    <x-filament::section icon="heroicon-o-rocket-launch">
        <x-slot name="heading">Finish setting up your company</x-slot>
        <x-slot name="description">
            Your setup checklist is incomplete. Continue where you left off to finish configuring your company.
        </x-slot>

        <x-filament::button
            tag="a"
            :href="\App\Filament\Pages\Onboarding::getUrl(panel: 'admin')"
        >
            Continue setup
        </x-filament::button>
        <x-filament::link tag="button" color="gray" wire:click="remindLater">
            Remind me later
        </x-filament::link>
        <x-filament::link tag="button" color="gray" wire:click="dismiss">
            Don't show again
        </x-filament::link>
    </x-filament::section>
    @endif
</x-filament-widgets::widget>

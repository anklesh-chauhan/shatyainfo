<x-filament-panels::page>
    <p>Prepare additional modules when your company needs them. Review employee permissions and approval routes before activation.</p>
    <x-filament::button tag="a" :href="\App\Filament\Pages\DataImport::getUrl(panel: 'admin')">Open configuration imports</x-filament::button>
    @foreach ([
        \App\Filament\Resources\Users\UserResource::class => 'Review user permissions',
        \App\Filament\Resources\ApprovalFlows\ApprovalFlowResource::class => 'Review approval flows',
        \App\Filament\Resources\ApprovalSettings\ApprovalSettingResource::class => 'Review approval settings',
    ] as $resource => $label)
        @if ($resource::canViewAny())
            <x-filament::button tag="a" color="gray" :href="$resource::getUrl(panel: 'admin')">{{ $label }}</x-filament::button>
        @endif
    @endforeach
    @if ($errors->any())
        <div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    @foreach ($modules as $key => $module)
        <x-filament::section :heading="$module['label']" wire:key="module-{{ $key }}">
            <p>{{ $module['enabled'] ? 'Activated' : 'Preparing' }}</p>
            <p>{{ count(array_filter($module['checks'])) }} of {{ count($module['checks']) }} configuration checks complete</p>
            <ul>
                @foreach ($module['checks'] as $label => $complete)
                    <li>{{ $complete ? '✓' : '○' }} {{ $label }}</li>
                @endforeach
            </ul>
            @if (! $module['enabled'])
                <label><input type="checkbox" wire:model="reviewed.{{ $key }}"> I reviewed employee permissions and confirmed approval routes or the no-approval choice in the existing module settings.</label>
                <x-filament::button wire:click="activate('{{ $key }}')" wire:loading.attr="disabled" :disabled="! $module['ready']">Activate module</x-filament::button>
            @else
                <p>Activated {{ $module['activation']['activated_at'] }}</p>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>

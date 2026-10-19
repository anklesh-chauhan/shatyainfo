@php($roles = \App\Models\JobRole::query()->orderBy('name')->pluck('name', 'id'))
<div class="setup-field">
    <label for="{{ $prefix }}-levels">Approval levels</label>
    <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-levels" wire:model.live="{{ $prefix }}.levels"><option value="1">L1 only</option><option value="2">L1 → L2</option></x-filament::input.select></x-filament::input.wrapper>
</div>
<div class="setup-field">
    <label for="{{ $prefix }}-territory">Flow territory</label>
    <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-territory" wire:model="{{ $prefix }}.territory_id"><option value="">All territories</option>@foreach (\App\Models\Territory::query()->orderBy('name')->pluck('name', 'id') as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
</div>
@for ($level = 1; $level <= (int) data_get($this, $prefix.'.levels', 1); $level++)
    <div class="setup-field">
        <label for="{{ $prefix }}-role-{{ $level }}">L{{ $level }} approver role</label>
        <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-role-{{ $level }}" wire:model="{{ $prefix }}.l{{ $level }}_role_id"><option value="">Select a role</option>@foreach ($roles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
    </div>
    <div class="setup-field">
        <label for="{{ $prefix }}-scope-{{ $level }}">L{{ $level }} approver territory scope</label>
        <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-scope-{{ $level }}" wire:model="{{ $prefix }}.l{{ $level }}_scope"><option value="all">Any territory</option><option value="self">Selected territory</option><option value="children">Selected territory and its children</option></x-filament::input.select></x-filament::input.wrapper>
    </div>
@endfor

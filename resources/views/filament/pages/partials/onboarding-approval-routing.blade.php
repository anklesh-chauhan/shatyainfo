@php($roles = \App\Models\JobRole::query()->orderBy('name')->pluck('name', 'id'))
<div class="setup-field">
    <label for="{{ $prefix }}-levels">Approval levels</label>
    <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-levels" wire:model.live="{{ $prefix }}.levels"><option value="1">L1 only</option><option value="2">L1 → L2</option></x-filament::input.select></x-filament::input.wrapper>
</div>
<div class="setup-field">
    <label for="{{ $prefix }}-territory">Flow territory</label>
    <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-territory" wire:model.live="{{ $prefix }}.territory_id"><option value="">All territories</option>@foreach (\App\Models\Territory::query()->orderBy('name')->pluck('name', 'id') as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
</div>
@for ($level = 1; $level <= (int) data_get($this, $prefix.'.levels', 1); $level++)
    <div class="setup-field">
        <label for="{{ $prefix }}-role-{{ $level }}">L{{ $level }} approver role</label>
        <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-role-{{ $level }}" wire:model.live="{{ $prefix }}.l{{ $level }}_role_id"><option value="">Select a role</option>@foreach ($roles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
    </div>
    <div class="setup-field">
        <label for="{{ $prefix }}-scope-{{ $level }}">L{{ $level }} approver territory scope</label>
        <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-scope-{{ $level }}" wire:model.live="{{ $prefix }}.l{{ $level }}_scope"><option value="all">Any territory</option><option value="self">Selected territory</option><option value="children">Selected territory and its children</option></x-filament::input.select></x-filament::input.wrapper>
    </div>
    @php($approverOptions = $this->approvalSetup()->approverOptions(data_get($this, $prefix, []), $level))
    <div class="setup-field">
        <label for="{{ $prefix }}-person-{{ $level }}">L{{ $level }} approver</label>
        <x-filament::input.wrapper><x-filament::input.select id="{{ $prefix }}-person-{{ $level }}" wire:model="{{ $prefix }}.l{{ $level }}_user_id" :required="count($approverOptions) > 1"><option value="">{{ count($approverOptions) === 1 ? 'Use the matching employee' : 'Choose an employee' }}</option>@foreach ($approverOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
        @if (count($approverOptions) > 1)<p class="setup-hint">Choose one of the {{ count($approverOptions) }} matching employees.</p>@elseif ($approverOptions === [] && data_get($this, $prefix.'.l'.$level.'_role_id'))<p class="setup-hint">No active employee with a login matches this role and territory.</p>@endif
    </div>
@endfor

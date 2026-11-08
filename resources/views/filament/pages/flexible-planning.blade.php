<x-filament-panels::page>
    <p>Optional customer intentions do not need MTP approval. New intentions are saved for your own fieldwork.</p>
    @if ($enabled)
        <form wire:submit="save">{{ $this->form }}
            <x-filament::button type="submit">Save intention</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="resetDraft">New draft</x-filament::button>
        </form>
    @else <p>Flexible planning is not enabled. Previously saved intentions remain available for review.</p> @endif
    <x-filament::section heading="Week review">
        <label>From <input type="date" wire:model.live="weekStart" /></label>
        <label>HQ <select wire:model.live="hqFilter"><option value="">All HQs</option>@foreach ($hqOptions as $hq)<option value="{{ $hq->id }}">{{ $hq->name }}</option>@endforeach</select></label>
        <label>Representative <select wire:model.live="userId">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
        <label>Patch <select wire:model.live="patchFilter"><option value="">All patches</option>@foreach (collect($report['entries'])->whereNotNull('patch')->pluck('patch')->unique('id') as $patch)<option value="{{ $patch['id'] }}">{{ $patch['name'] }}</option>@endforeach</select></label>
        <label>Status <select wire:model.live="statusFilter"><option value="">All intentions</option>@foreach (['planned' => 'Planned', 'started' => 'Started', 'missed' => 'Missed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <p>Current HQ: {{ $report['hq']['name'] ?? 'Not assigned' }}</p>
        <p>Intended {{ $report['intended'] }} · Completed {{ $report['completed'] }} · Missed {{ $report['missed'] }} · Unplanned calls {{ count($report['unplanned_visits']) }}</p>
        <p>Totals cover the whole week. Completion means a completed visit. Official coverage still requires an approved DCR.</p>
        @php($shownEntries = collect($report['entries'])->filter(fn ($entry) => (! $patchFilter || ($entry['patch']['id'] ?? null) == $patchFilter) && (! $statusFilter || $entry['execution_status'] === $statusFilter)))
        @foreach ($shownEntries as $entry)
            <x-filament::section wire:key="intention-{{ $entry['id'] }}" heading="{{ $entry['account']['name'] ?? 'Customer unavailable' }}">
                <p>{{ $entry['date'] }} · {{ $entry['execution_status'] }} · {{ $entry['patch']['name'] ?? 'No patch' }}</p>
                <p>{{ $entry['notes'] }}</p>
                @if ($enabled && $report['user_id'] === auth()->id())
                    @if ($entry['editable'])
                        <x-filament::button color="gray" wire:click="editIntention({{ $entry['id'] }})">Edit</x-filament::button>
                        <x-filament::button color="gray" wire:click="cancelIntention({{ $entry['id'] }}, {{ $entry['version'] }})" wire:confirm="Cancel this intention?">Cancel</x-filament::button>
                    @endif
                    @if ($entry['status'] === 'active' && $entry['date'] === today()->toDateString())
                        <x-filament::button wire:click="startVisit({{ $entry['id'] }}, {{ $entry['version'] }})">Open visit</x-filament::button>
                    @endif
                @endif
            </x-filament::section>
        @endforeach
        @if ($shownEntries->isEmpty())<p>No intentions in this view.</p>@endif
        @foreach ($report['unplanned_visits'] as $visit)<p>Unplanned {{ $visit['date'] }} · {{ $visit['status'] }} · {{ $visit['reason'] }}</p>@endforeach
    </x-filament::section>
</x-filament-panels::page>

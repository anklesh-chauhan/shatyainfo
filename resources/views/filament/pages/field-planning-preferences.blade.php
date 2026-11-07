<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <x-filament::button type="submit">Save proposal</x-filament::button>
    </form>

    <x-filament::section heading="Latest 10 saved proposals">
        <p>Each save keeps a permanent revision. Active revision: {{ $activePolicy?->version ?? 'Existing workflow' }}. Activation is a separate action after saving.</p>
        @forelse ($revisions as $revision)
            <details wire:key="planning-revision-{{ $revision->id }}">
                <summary>Revision {{ $revision->version }} — {{ $modeLabels[$revision->mode->value] }} — {{ $revision->creator?->name ?? 'User '.$revision->created_by }}</summary>
                <p>Saved: {{ $revision->created_at->format('Y-m-d H:i') }}</p>
                <p>Requested start: {{ $revision->proposed_effective_on?->format('Y-m-d') ?? 'Not specified' }}</p>
                <p>Reason: {{ $revision->reason }}</p>
                <dl>
                    @foreach ($ruleLabels as $key => $label)
                        <dt>{{ $label }}</dt>
                        <dd>{{ $revision->rules[$key] ? 'Yes' : 'No' }}</dd>
                    @endforeach
                    <dt>Unplanned calls</dt>
                    <dd>{{ $unplannedLabels[$revision->rules['unplanned_calls']] }}</dd>
                </dl>
            </details>
        @empty
            <p>No planning proposals have been saved.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

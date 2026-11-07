<x-filament-panels::page>
    <label>Representative <select wire:model.live="userId">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
    <label>Month <input type="month" wire:model.live="month" max="{{ today()->format('Y-m') }}" /></label>
    <x-filament::section heading="Monthly coverage">
        <p>Current HQ: {{ $report['hq']['name'] ?? 'Not assigned' }} · As of {{ $report['as_of'] }}</p>
        <p>Unique coverage: {{ $report['covered_customers'] }}/{{ $report['eligible_customers'] }} customers ({{ $report['coverage_percent'] === null ? 'N/A' : $report['coverage_percent'].'%' }})</p>
        <p>Frequency: {{ $report['credited_frequency_calls'] }}/{{ $report['target_calls'] }} calls ({{ $report['frequency_percent'] === null ? 'N/A' : $report['frequency_percent'].'%' }})</p>
        <p>Joint participation: {{ $report['joint_participations'] }} · Calls outside assignment: {{ $report['unassigned_completed_calls'] }}</p>
        <p>Only completed calls on approved DCRs count. Targets are prorated by assigned calendar days; joint participation does not add owner coverage.</p>
    </x-filament::section>
    <x-filament::section heading="Assigned customers">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead><tr><th>Customer</th><th>Target</th><th>Completed</th><th>Unapproved</th><th>Remaining</th><th>Status</th></tr></thead>
                <tbody>@forelse ($report['customers'] as $customer)
                    <tr><td>{{ $customer['name'] }}</td><td>{{ $customer['target_calls'] ?? 'Not configured' }}</td><td>{{ $customer['completed_calls'] }}</td><td>{{ $customer['pending_calls'] }}</td><td>{{ $customer['remaining_calls'] ?? 'N/A' }}</td><td>{{ str_replace('_', ' ', $customer['status']) }}</td></tr>
                @empty<tr><td colspan="6">No customer assignments for this month.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

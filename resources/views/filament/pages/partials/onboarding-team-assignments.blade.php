<section class="setup-import-card" aria-label="Bulk team assignments">
    <h3>Assign HQs to selected MRs</h3>
    <p>Select MRs in the team table below, then choose an HQ once for the group. Existing HQs are kept unless you choose to replace them.</p>
    <p class="setup-hint">{{ $directDcr ? 'HQ is optional for Report visits directly. You can skip this section.' : ($workflow === 'formal_mtp' ? 'Each changed HQ must share a territory with one of the MR’s positions. The preview identifies incompatible MRs before anything is saved.' : 'Choose an active HQ. Territory and patch links are optional for Plan when useful.') }}</p>
    <div class="setup-field">
        <label for="bulk-hq">HQ for selected MRs</label>
        <x-filament::input.wrapper><x-filament::input.select id="bulk-hq" wire:model.live="bulkHqId">
            <option value="">Choose an HQ</option>
            @foreach (\App\Models\LocationMaster::query()->where('is_active', true)->orderBy('name')->get() as $hq)
                <option value="{{ $hq->id }}">{{ $hq->name }} ({{ $hq->location_code }})</option>
            @endforeach
        </x-filament::input.select></x-filament::input.wrapper>
    </div>
    <label><input type="checkbox" wire:model.live="replaceExistingHqs"> Replace existing HQ assignments for selected MRs</label>
    <p><x-filament::button color="gray" wire:click="previewBulkHqs" wire:loading.attr="disabled">Preview HQ assignments</x-filament::button></p>
    @if ($hqAssignmentPreview !== [])
        <div class="setup-note">
            <p>Review HQ changes. Nothing has been saved.</p>
            @foreach ($hqAssignmentPreview['errors'] as $message)<p class="setup-error" role="alert">{{ $message }}</p>@endforeach
            <div style="overflow:auto;max-height:320px"><table class="setup-territories">
                <thead><tr><th>MR</th><th>Current HQ</th><th>Proposed HQ</th><th>Action</th></tr></thead>
                <tbody>@foreach ($hqAssignmentPreview['plans'] as $plan)<tr><td>{{ $plan['name'] }}</td><td>{{ $plan['current'] ?? 'None' }}</td><td>{{ $plan['change'] ? $plan['hq'] : $plan['current'] }}</td><td>{{ $plan['action'] }}</td></tr>@endforeach</tbody>
            </table></div>
            @if ($hqAssignmentPreview['errors'] === [])<x-filament::button wire:click="confirmBulkHqs" wire:loading.attr="disabled">Confirm HQ assignments</x-filament::button>@endif
        </div>
    @endif
</section>
<section class="setup-import-card" aria-label="Bulk customer mapping">
    <h3>Match customers to selected MRs by territory</h3>
    <p>Select MRs in the team table. A customer’s territory or imported patch can suggest an owner when exactly one selected MR covers that territory through a position. Review up to 500 changes at a time.</p>
    <p><label><input type="checkbox" wire:model.live="allowCustomerTransfers"> Allow transfers from existing owners (shown in preview)</label></p>
    <x-filament::button color="gray" wire:click="previewTerritoryCustomerMappings" wire:loading.attr="disabled">Preview territory matches</x-filament::button>
    @if ($territoryMappingPreview !== [])
        <div class="setup-note">
            <p>{{ count($territoryMappingPreview['plans']) }} proposed changes · {{ $territoryMappingPreview['skipped']['ambiguous'] }} conflicting matches · {{ $territoryMappingPreview['skipped']['existing'] }} kept with their current owner.</p>
            @if ($territoryMappingPreview['skipped']['other'])
                <p>Preview again after saving to review the remaining {{ $territoryMappingPreview['skipped']['other'] }} matches.</p>
            @endif
            @if ($territoryMappingPreview['plans'] !== [])
                <div style="overflow:auto;max-height:320px"><table class="setup-territories">
                    <thead><tr><th>Customer</th><th>Current owner</th><th>Proposed owner</th><th>Action</th></tr></thead>
                    <tbody>@foreach ($territoryMappingPreview['plans'] as $plan)<tr><td>{{ $plan['name'] }} ({{ $plan['code'] }})</td><td>{{ $plan['current'] ?? 'Unassigned' }}</td><td>{{ $plan['owner'] }}</td><td>{{ $plan['action'] }}</td></tr>@endforeach</tbody>
                </table></div>
                <x-filament::button wire:click="confirmTerritoryCustomerMappings" wire:loading.attr="disabled">Confirm territory matches</x-filament::button>
            @else
                <p>No changes to apply. Use the customer mapping upload for manual assignments, including customers without a territory or patch.</p>
            @endif
        </div>
    @endif
    <h3>Assign customers with one upload</h3>
    <p>Download existing customers, fill <strong>owner_email</strong> using the Representatives sheet, then upload. Blank owner emails keep the current owner. Remove rows you do not need; upload up to 500 customers at a time.</p>
    <p class="setup-hint">This upload applies to the customers in the file, independently of the selected launch team. Customer details, call targets, territories and patches stay unchanged. All working methods use the same customer ownership mapping.</p>
    <x-filament::button color="gray" wire:click="downloadCustomerMappings" wire:loading.attr="disabled">Download customer mapping Excel</x-filament::button>
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewCustomerMappings', 'fileProperty' => 'mappingFile', 'inputId' => 'customer-mapping-file', 'accept' => '.xlsx,.csv', 'fileHelp' => 'Excel or CSV · Up to 500 customers · Maximum 2 MB.'])
    @if ($mappingPreview !== [])
        <div class="setup-note">
            <h4>Review customer assignments</h4>
            <p>{{ count($mappingPreview['plans']) }} customers reviewed · {{ count($mappingPreview['errors']) }} errors. Nothing has been saved.</p>
            @foreach ($mappingPreview['errors'] as $message)<p class="setup-error" role="alert">{{ $message }}</p>@endforeach
            <div style="overflow:auto;max-height:320px"><table class="setup-territories">
                <thead><tr><th>Row</th><th>Customer</th><th>Current owner</th><th>Proposed owner</th><th>Action</th></tr></thead>
                <tbody>@foreach ($mappingPreview['plans'] as $plan)<tr><td>{{ $plan['line'] }}</td><td>{{ $plan['name'] }} ({{ $plan['code'] }})</td><td>{{ $plan['current'] ?? 'Unassigned' }}</td><td>{{ $plan['owner'] ?? 'Unassigned' }}</td><td>{{ $plan['action'] }}</td></tr>@endforeach</tbody>
            </table></div>
            @if ($mappingPreview['errors'] === [])<x-filament::button wire:click="confirmCustomerMappings" wire:loading.attr="disabled">Confirm customer assignments</x-filament::button>@endif
        </div>
    @endif
</section>

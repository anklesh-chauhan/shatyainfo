<section class="setup-import-card" aria-label="Territory import">
    <div class="setup-card-heading"><span class="setup-card-number">↑</span><div><h3>Set up your territories</h3><p>Import territories before assigning employees to positions.</p></div></div>
    <div class="setup-card-stats"><span>{{ $checklist['territory_count'] }} territories saved</span></div>
    <x-filament::button color="gray" wire:click="downloadTerritoryTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <details class="setup-optional">
        <summary>How to prepare your territory file</summary>
        <p>Fill the Territories sheet. Use a unique code for each territory and parent_code for its parent. Leave parent_code blank for a root territory.</p>
        <p>Enter cities_and_areas as City|Area|PIN. Separate multiple locations with semicolons. City names must already exist.</p>
        <p>To link HQs, import HQ locations first, then enter their location codes in hq_codes, separated by semicolons. Blank cells keep existing links. HQ links are optional for Direct DCR.</p>
        <p>@if ($this->usesDivisions())Enter division_codes and matching division_names. Missing divisions are created on import.@else Leave division_codes and division_names blank; your company does not use divisions.@endif</p>
        <p>Read the template’s Instructions sheet for examples. Preview the file, fix any errors, then select Confirm import.</p>
    </details>
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewTerritories', 'fileProperty' => 'territoryFile', 'inputId' => 'territory-file', 'accept' => '.xlsx,.csv', 'fileHelp' => 'Excel or CSV · Up to 500 rows · Maximum 2 MB.'])
                    @if ($territoryPreview !== [])
                        <div class="setup-note">
                            <h3>Review before importing</h3>
                            @php($divisionPlans = collect($territoryPreview)->pluck('division_plans')->flatten(1)->unique('code'))
                            @if ($divisionPlans->isNotEmpty())
                                <h4>Divisions</h4><ul>@foreach ($divisionPlans as $division)<li>{{ $division['code'] }} — {{ $division['name'] }} — {{ $division['action'] }}</li>@endforeach</ul>
                            @endif
                            <p>Existing records are kept. Conflicting values must be corrected before importing.</p>
                            <div style="overflow:auto;max-height:320px">
                                <table class="setup-territories"><thead><tr><th>Code</th><th>Name</th><th>Parent code</th><th>Status</th><th>Division codes</th><th>Cities and Areas</th><th>HQ codes</th><th>Action</th></tr></thead><tbody>
                                    @foreach ($territoryPreview as $row)
                                        <tr><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['parent_code'] ?: 'Root territory' }}</td><td>{{ $row['status'] }}</td><td>{{ $row['division_codes'] }}</td><td>{{ $row['cities_and_areas'] }}</td><td>{{ $row['hq_codes'] ?: 'Keep existing links' }}</td><td>{{ $row['action'] }}</td></tr>
                                    @endforeach
                                </tbody></table>
                            </div>
                            @if (\App\Filament\Pages\FieldTravelImport::canAccess())
                                <p class="mt-3"><a href="{{ \App\Filament\Pages\DataImport::getUrl(['module' => 'field_routes'], panel: 'admin') }}">Optional: import patches and standard fares after saving territories</a>. Add customer assignments once customers exist.</p>
                            @endif
                            <x-filament::button wire:click="importTerritories" wire:loading.attr="disabled">Confirm import</x-filament::button>
                        </div>
                    @endif

</section>

                    <h2>Set up your territories</h2>
                    <p>Upload territories before employees so positions can be assigned using territory codes.</p>
                    <p class="setup-hint">{{ $checklist['territory_count'] }} territories available. Cities and Areas are required. Division links are {{ $this->usesDivisions() ? 'required' : 'optional' }} for your company. Parents and children can appear in any row order. Codes are matched without case sensitivity. Blank status means active.</p>
                    <div class="setup-note">@if ($this->usesDivisions())Enter division_codes and matching division_names in the territory file. Missing divisions will be created together with territories. Existing divisions are reused by code.@else Your company does not use divisions. Leave division_codes and division_names blank. Existing divisions and links are preserved.@endif</div>
                    <p class="setup-hint">To change whether your company uses divisions, open Company information in the Setup Checklist.</p>
                    <button type="button" class="setup-text-button" wire:click="downloadTerritoryTemplate">Download Excel template with instructions</button>
                    <details class="setup-optional"><summary>How to prepare your territory file</summary><p>Read the Instructions and Examples sheets in the template. Fill only the Territories sheet; example rows are never imported.</p><p>Enter cities_and_areas on every row. Enter division_codes when your company uses divisions, and matching division_names for new divisions; otherwise leave both blank. Separate multiple divisions or locations with semicolons. Locations use City|Area|PIN, for example Mumbai|Andheri|400058. Locations match by city and PIN only. The oldest matching area is reused; otherwise the supplied area is created on import. City names must already exist. PINs do not need to be unique.</p><p>Use one unique code per territory. Leave parent_code blank for a root territory. Parents may appear before or after their children. Upload, preview, then confirm the import.</p><p>Identical existing records are kept. Conflicting records, missing parents and circular hierarchies must be corrected before saving.</p></details>
                    <form wire:submit="previewTerritories" class="setup-form">
                        <div class="setup-field"><label for="territory-file">Territory Excel or CSV (up to 500 rows, 2 MB)</label><input id="territory-file" type="file" accept=".xlsx,.csv" wire:model="territoryFile"></div>
                        <x-filament::button type="submit" wire:loading.attr="disabled">Preview territories</x-filament::button>
                        <p wire:loading wire:target="territoryFile">Uploading file…</p>
                    </form>
                    @if ($territoryPreview !== [])
                        <div class="setup-note">
                            <h3>Review before importing</h3>
                            @php($divisionPlans = collect($territoryPreview)->pluck('division_plans')->flatten(1)->unique('code'))
                            @if ($divisionPlans->isNotEmpty())
                                <h4>Divisions</h4><ul>@foreach ($divisionPlans as $division)<li>{{ $division['code'] }} — {{ $division['name'] }} — {{ $division['action'] }}</li>@endforeach</ul>
                            @endif
                            <p>Existing records are kept. Conflicting values must be corrected before importing.</p>
                            <div style="overflow:auto;max-height:320px">
                                <table class="setup-territories"><thead><tr><th>Code</th><th>Name</th><th>Parent code</th><th>Status</th><th>Division codes</th><th>Cities and Areas</th><th>Action</th></tr></thead><tbody>
                                    @foreach ($territoryPreview as $row)
                                        <tr><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['parent_code'] ?: 'Root territory' }}</td><td>{{ $row['status'] }}</td><td>{{ $row['division_codes'] }}</td><td>{{ $row['cities_and_areas'] }}</td><td>{{ $row['action'] }}</td></tr>
                                    @endforeach
                                </tbody></table>
                            </div>
                            @if (\App\Filament\Pages\FieldTravelImport::canAccess())
                                <p class="mt-3"><a href="{{ \App\Filament\Pages\DataImport::getUrl(['module' => 'field_routes'], panel: 'admin') }}">Optional: import patches and standard fares after saving territories</a>. Add customer assignments once customers exist.</p>
                            @endif
                            <x-filament::button wire:click="importTerritories" wire:loading.attr="disabled">Import reviewed territories</x-filament::button>
                        </div>
                    @endif

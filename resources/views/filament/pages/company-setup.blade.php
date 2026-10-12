<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $step === 1 ? '1. Organization information' : '2. Employee information' }}</x-slot>
        <x-slot name="description">Start with the essentials. You can skip and return to Company Setup later.</x-slot>

        @if ($errors->any())
            <div role="alert" class="mb-4 text-danger-600">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($step === 1)
            <form wire:submit="saveOrganization" class="space-y-4">
                @foreach (['name' => 'Organization name', 'email' => 'Organization email', 'phone' => 'Phone number'] as $field => $label)
                    <div>
                        <label for="org-{{ $field }}">{{ $label }} *</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" wire:model="organization.{{ $field }}" required />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
                <x-filament::button type="submit" wire:loading.attr="disabled">Save and continue</x-filament::button>
            </form>
        @else
            <div class="space-y-4">
                <p>{{ $organization['name'] }} · {{ $organization['email'] }}</p>
                <x-filament::button color="gray" wire:click="editOrganization">Edit organization</x-filament::button>
                <p>Enter employee rows or upload a CSV. Employee code, first name, last name and mobile are required; email is optional. These are employee records; use User Management to arrange login access.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button color="gray" wire:click="downloadTemplate">Download CSV template</x-filament::button>
                    <input aria-label="Employee CSV" type="file" accept=".csv,.txt" wire:model="file" />
                    <x-filament::button color="gray" wire:click="previewImport" wire:loading.attr="disabled">Load CSV into table</x-filament::button>
                </div>
                <p>Loading a CSV replaces unsaved rows. Review the rows before saving. Up to 500 employees per import; existing codes, emails and mobile numbers cannot be duplicated.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead><tr>
                            @foreach (['Employee code *', 'First name *', 'Last name *', 'Email', 'Mobile *'] as $label)<th class="p-2">{{ $label }}</th>@endforeach
                            <th class="p-2">Action</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $index => $row)
                                <tr wire:key="employee-row-{{ $index }}">
                                    @foreach (\App\Services\CompanyOnboardingService::EMPLOYEE_COLUMNS as $field)
                                        <td class="p-2"><x-filament::input.wrapper><x-filament::input aria-label="Row {{ $index + 1 }} {{ str_replace('_', ' ', $field) }}" wire:model="rows.{{ $index }}.{{ $field }}" /></x-filament::input.wrapper></td>
                                    @endforeach
                                    <td class="p-2"><x-filament::button color="gray" wire:click="removeRow({{ $index }})">Remove</x-filament::button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-filament::button color="gray" wire:click="addRow">Add row</x-filament::button>
                    <x-filament::button wire:click="saveEmployees" wire:loading.attr="disabled">Save employee rows</x-filament::button>
                    <x-filament::button color="gray" wire:click="finish">Finish setup</x-filament::button>
                </div>
                @if ($savedCount)<p>{{ $savedCount }} employees saved in this session.</p>@endif
                <p>Finish setup after saving your rows. You can also finish now and add employees later.</p>
            </div>
        @endif
        <div class="mt-6"><x-filament::button color="gray" wire:click="skip">Skip and finish later</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>

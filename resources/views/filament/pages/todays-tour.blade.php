<x-filament-panels::page>
    <div class="mb-6 flex items-center justify-between gap-4 w-full relative overflow-hidden h-11 md:h-auto">

        <div class="w-full pr-36 transition-all duration-300 focus-within:pr-0 md:pr-0 md:flex-[3]">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.500ms="search"
                    placeholder="Search companies by name..."
                />
            </x-filament::input.wrapper>
        </div>

        <div class="absolute right-0 top-1/2 -translate-y-1/2 flex items-center justify-end gap-1 min-w-max transition-all duration-300 pointer-events-auto [div:focus-within~&]:translate-x-full [div:focus-within~&]:opacity-0 md:relative md:top-0 md:translate-y-0 md:flex-[1] md:[div:focus-within~&]:translate-x-0 md:[div:focus-within~&]:opacity-100">
            <x-filament::icon-button
                icon="heroicon-m-chevron-left"
                wire:click="previousDay"
                color="gray"
            />

            <div class="px-2 py-2 text-sm font-semibold whitespace-nowrap">
                {{ \Carbon\Carbon::parse($selectedDate)->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}
            </div>

            <x-filament::icon-button
                icon="heroicon-m-chevron-right"
                wire:click="nextDay"
                color="gray"
            />
        </div>

    </div>

    @php
        // Check if there are ANY companies left across all patches after filtering
        $hasResults = $todayPlans->contains(fn($plan) =>
            $plan->patches->contains(fn($patch) => $patch->companies->isNotEmpty())
        );
    @endphp

    @if (!$hasResults)
        <x-filament::card class="flex flex-col items-center justify-center p-12 text-center space-y-2">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-8 w-8 text-gray-400" />
            <h2 class="text-lg font-bold">No results found</h2>
            <p class="text-sm text-gray-500">Try adjusting your search term "{{ $search }}".</p>
        </x-filament::card>
    @else

        <div class="grid gap-y-6">
            @foreach ($todayPlans as $detail)
                <div class="grid gap-y-2">
                    @foreach ($detail->patches as $patch)
                        <x-filament::section collapsible>
                            <x-slot name="heading">
                                <span class="text-xl font-bold tracking-tight">
                                    Patch: {{ $patch->name }}
                                </span>
                            </x-slot>

                            @if ($patch->companies->isEmpty())
                                <p class="text-sm text-gray-500 italic">No customers mapped to this patch.</p>
                            @else
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4"> {{-- Increased gap for better spacing --}}
                                    @foreach ($patch->companies as $company)
                                        @php
                                            // Fetch the visit record for this specific company and tour plan detail
                                            // 1. Get the visit record
                                            $visitRecord = \App\Models\Visit::query()
                                                ->where('employee_id', auth()->id())
                                                ->where('sales_tour_plan_detail_id', $detail->id)
                                                ->where('patch_id', $patch->id)
                                                ->whereHas('visitables', fn ($q) =>
                                                    $q->where('visitable_type', \App\Models\AccountMaster::class)
                                                    ->where('visitable_id', $company->id)
                                                )->first();

                                            // 2. Define all UI variables in one place
                                            $status = $this->getVisitStatus($company->id, $detail->id, $patch->id);
                                            $visitId = $visitRecord?->id;

                                            // 3. Fix the $contact variable
                                            $contact = $company->contactDetails->first();
                                        @endphp

                                        <x-filament::card class="relative flex flex-col justify-between space-y-4">

                                            {{-- 🟢 Status Indicator Dot (Top Right) --}}
                                            <div class="absolute top-3 right-3 flex items-center gap-1.5">
                                                @if($status === 'started')
                                                    <span class="relative flex h-2 w-2">
                                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-warning-400 opacity-75"></span>
                                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-warning-500"></span>
                                                    </span>
                                                    <span class="text-[10px] font-bold text-warning-600 uppercase tracking-wider">Started</span>

                                                @elseif($status === 'completed')
                                                    <span class="relative flex h-2 w-2 rounded-full bg-success-500"></span>
                                                    <span class="text-[10px] font-bold text-success-600 uppercase tracking-wider">Done</span>

                                                @elseif($status === 'cancelled')
                                                    <span class="relative flex h-2 w-2 rounded-full bg-danger-500"></span>
                                                    <span class="text-[10px] font-bold text-danger-600 uppercase tracking-wider">Cancelled</span>

                                                @elseif($status === 'rescheduled')
                                                    <span class="relative flex h-2 w-2 rounded-full bg-info-500"></span>
                                                    <span class="text-[10px] font-bold text-info-600 uppercase tracking-wider">Rescheduled</span>

                                                @else
                                                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Planned</span>
                                                @endif
                                            </div>

                                            <div class="space-y-2">
                                                @if ($company->typeMaster?->name)
                                                    <x-filament::badge color="primary" size="sm" class="w-fit">
                                                        {{ $company->typeMaster->name }}
                                                    </x-filament::badge>
                                                @endif

                                                <p class="font-semibold text-gray-900 dark:text-white line-clamp-2 pr-12">
                                                    {{ $company->name }}
                                                </p>

                                                @if ($company->phone_number)
                                                    <div class="flex items-center gap-2">
                                                        <x-filament::icon icon="heroicon-m-phone" class="w-4 h-4 text-gray-400" />
                                                        <a href="tel:{{ $company->phone_number }}"
                                                           class="text-primary-600 hover:underline text-sm font-medium">
                                                            {{ $company->phone_number }}
                                                        </a>
                                                    </div>
                                                @endif

                                                @if ($contact)
                                                    <div class="text-sm text-gray-600 dark:text-gray-300 space-y-1 pt-1 border-t border-gray-100 dark:border-gray-800">
                                                        <div class="flex items-center gap-2">
                                                            <x-filament::icon icon="heroicon-m-user" class="w-4 h-4 text-gray-400" />
                                                            <span class="font-medium text-gray-800 dark:text-gray-200">
                                                                {{ $contact->full_name }}
                                                            </span>
                                                        </div>

                                                        @if ($contact->mobile_number)
                                                            <div class="flex items-center gap-2 pl-6">
                                                                <a href="tel:{{ $contact->mobile_number }}"
                                                                   class="text-xs text-gray-500 hover:underline">
                                                                    {{ $contact->mobile_number }}
                                                                </a>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <p class="text-xs text-gray-400 italic pt-1 border-t border-gray-100 dark:border-gray-800">No contact person</p>
                                                @endif
                                            </div>

                                            <div class="flex flex-col gap-2 w-full mt-3">
                                                {{-- 1. Main Action Button --}}
                                                <x-filament::button
                                                    size="sm"
                                                    icon="{{ match($status) {
                                                        'completed' => 'heroicon-m-check-badge',
                                                        'cancelled' => 'heroicon-m-x-circle',
                                                        'rescheduled' => 'heroicon-m-arrow-path',
                                                        'draft' => 'heroicon-m-play-pause',
                                                        default => 'heroicon-m-play'
                                                    } }}"
                                                    color="{{ match($status) {
                                                        'completed' => 'gray',
                                                        'cancelled' => 'danger',
                                                        'rescheduled' => 'info',
                                                        'draft' => 'warning',
                                                        default => 'primary'
                                                    } }}"
                                                    wire:click="openVisit({{ $detail->id }}, {{ $detail->sales_tour_plan_id }}, {{ $detail->territory_id }}, {{ $patch->id }}, {{ $company->id }})"
                                                    class="w-full shadow-sm"
                                                    :disabled="in_array($status, ['completed', 'cancelled', 'rescheduled'])"
                                                >
                                                    @if($status === 'started')
                                                        Started
                                                    @elseif($status === 'completed')
                                                        Done
                                                    @elseif($status === 'cancelled')
                                                        Cancelled
                                                    @elseif($status === 'rescheduled')
                                                        Rescheduled
                                                    @else
                                                        Planned
                                                    @endif

                                                </x-filament::button>

                                                {{-- 2. Secondary Row (Cancel / Reschedule) --}}
                                                {{-- Only show if the visit is not already finalized --}}
                                                @if(!in_array($status, ['completed', 'cancelled', 'rescheduled']))
                                                    <div class="flex gap-2 w-full">
                                                        <div class="flex-1">
                                                            {{ ($this->cancelAction)([
                                                                'visit_id' => $visitId,
                                                                'company_id' => $company->id,
                                                                'detail_id' => $detail->id,
                                                                'patch_id' => $patch->id
                                                                ])
                                                            }}
                                                        </div>
                                                        <div class="flex-1">
                                                            {{ ($this->rescheduleAction)([
                                                                'visit_id' => $visitId,
                                                                'company_id' => $company->id,
                                                                'detail_id' => $detail->id,
                                                                'patch_id' => $patch->id
                                                                ])
                                                            }}
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>

                                        </x-filament::card>
                                    @endforeach
                                </div>
                            @endif
                        </x-filament::section>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>

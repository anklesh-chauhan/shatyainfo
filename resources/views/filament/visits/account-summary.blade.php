@php
    $account = $record?->primaryCompany();
@endphp

@if ($account)
    <div class="rounded-xl bg-white p-4 space-y-3">

        {{-- Account name --}}
        <div>
            <div class="text-lg font-semibold">
                {{ $account->name }}
            </div>
            <div class="text-sm text-gray-500">
                {{ $account->account_code }}
                · {{ $account->typeMaster?->name }}
            </div>
        </div>

        {{-- Rating --}}
        @if ($account->ratingType)
            <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs">
                ⭐ {{ $account->ratingType->name }}
            </span>
        @endif

        {{-- Actions --}}
        <div class="flex gap-3 pt-2">
            @if ($account->phone_number)
                <a href="tel:{{ $account->phone_number }}"
                   class="text-primary text-sm font-medium">
                    📞 Call
                </a>
            @endif

            @if ($account->email)
                <a href="mailto:{{ $account->email }}"
                   class="text-primary text-sm font-medium">
                    ✉️ Email
                </a>
            @endif
        </div>

        {{-- Last visit --}}
        @php
            $lastVisit = $account->visits()
                ->where('id', '!=', $record->id)
                ->latest('visit_date')
                ->first();
        @endphp

        @if ($lastVisit)
            <div class="text-xs text-gray-500 pt-2">
                Last visit: {{ $lastVisit->visit_date->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}
            </div>
        @endif

        {{-- View full --}}
        <div class="pt-2">
            <a href="{{ route('filament.admin.resources.account-masters.view', $account) }}"
               class="text-sm text-primary underline">
                View full account
            </a>
        </div>

    </div>
@else
    <div class="text-sm text-gray-500">
        No customer linked to this visit.
    </div>
@endif

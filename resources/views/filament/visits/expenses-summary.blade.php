@php
    /** @var \App\Models\Visit|null $record */
    $dcr = $record?->salesDcr;
@endphp

@if ($dcr)
    <div class="rounded-xl bg-white p-4 space-y-3">
        <div class="flex items-baseline justify-between gap-4">
            <div>
                <div class="text-lg font-semibold">
                    Expenses for {{ $dcr->dcr_date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}
                </div>
                <div class="text-sm text-gray-500">
                    Total: ₹{{ number_format((float) $dcr->total_expense, 2) }}
                </div>
            </div>
        </div>

        @php
            $expenses = $dcr->expenses()
                ->with(['expenseType', 'transportMode'])
                ->orderBy('id')
                ->get();
        @endphp

        @if ($expenses->isEmpty())
            <div class="text-sm text-gray-500">
                No expenses recorded for this DCR yet.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs uppercase text-gray-500">
                            <th class="py-2 pr-4">Expense</th>
                            <th class="py-2 pr-4">Transport</th>
                            <th class="py-2 pr-4 text-right">Qty</th>
                            <th class="py-2 pr-4 text-right">Rate</th>
                            <th class="py-2 pr-4 text-right">Amount</th>
                            <th class="py-2 pr-4 text-center">Auto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expenses as $expense)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-4">
                                    {{ $expense->expenseType?->name ?? '—' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ $expense->transportMode?->name ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ number_format((float) $expense->quantity, 2) }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    ₹{{ number_format((float) $expense->rate, 2) }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    ₹{{ number_format((float) $expense->amount, 2) }}
                                </td>
                                <td class="py-2 pr-4 text-center">
                                    {{ $expense->is_auto_calculated ? '✓' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@else
    <div class="text-sm text-gray-500">
        No DCR linked to this visit yet.
    </div>
@endif


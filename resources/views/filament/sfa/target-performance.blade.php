<div class="space-y-6">
    <p class="text-sm text-gray-500">Verified secondary sales · {{ $data['target']['starts_on'] }} to {{ $data['target']['ends_on'] }} · As of {{ $data['as_of'] }}</p>
    <div class="grid grid-cols-2 gap-4">
        <div class="rounded-xl border p-4">
            <p>Net achievement · ₹ lakhs</p>
            <p class="text-2xl font-semibold">{{ number_format($data['ledger']['net_paise'] / 10000000, 2) }} / {{ number_format($data['target']['target_value_lakhs'], 2) }}</p>
            <p>{{ $data['value_achievement_percent'] }}%</p>
            <progress class="w-full" max="100" value="{{ max(0, min(100, $data['value_achievement_percent'])) }}"></progress>
        </div>
        <div class="rounded-xl border p-4">
            <p>Quantity achievement · base units</p>
            <p class="text-2xl font-semibold">{{ number_format($data['ledger']['net_base_quantity'], 3) }} / {{ number_format($data['target']['target_quantity'], 3) }}</p>
            <p>{{ $data['quantity_achievement_percent'] }}%</p>
            <progress class="w-full" max="100" value="{{ max(0, min(100, $data['quantity_achievement_percent'])) }}"></progress>
        </div>
    </div>
    <table class="w-full text-left text-sm">
        <thead><tr><th>Secondary-sales calculation</th><th>₹ lakhs</th></tr></thead>
        <tbody>
            @foreach (['gross_paise' => 'Gross secondary sales', 'discount_paise' => 'Approved schemes / discounts', 'returns_paise' => 'Verified sales returns', 'breakage_expiry_paise' => 'Verified breakage / expiry', 'net_paise' => 'Net secondary sales'] as $key => $label)
                <tr><td class="py-2">{{ $label }}</td><td>{{ number_format($data['ledger'][$key] / 10000000, 4) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <div>
        <p class="font-semibold">Representative sales credit</p>
        <table class="w-full text-left text-sm"><thead><tr><th>Representative</th><th>₹ lakhs</th><th>Base units</th></tr></thead><tbody>
            @foreach ($data['credits'] as $credit)
                <tr><td class="py-2">{{ $credit['name'] }}</td><td>{{ number_format($credit['value_paise'] / 10000000, 4) }}</td><td>{{ number_format($credit['base_quantity'], 3) }}</td></tr>
            @endforeach
        </tbody></table>
        @foreach ($data['months'] as $month)
            <p class="text-sm text-gray-500">{{ $month['month'] }} · {{ str_replace('_', ' ', $month['allocation']['state']) }}</p>
        @endforeach
        @if ($data['unallocated_paise'] !== 0 || $data['unallocated_base_quantity'] != 0)
            <p class="mt-2 font-semibold">₹ {{ number_format($data['unallocated_paise'] / 100, 2) }} / {{ $data['unallocated_base_quantity'] }} base units remain unallocated. Complete ownership and attendance history before using employee credit.</p>
        @endif
    </div>
    <p class="text-sm">Shortfall: ₹ {{ number_format($data['value_shortfall_paise'] / 100, 2) }} / {{ $data['quantity_shortfall'] }} base units.</p>
    @foreach (['stockists' => 'By stockist', 'products' => 'By product'] as $key => $label)
        <details><summary class="cursor-pointer font-semibold">{{ $label }}</summary>
            <table class="w-full text-left text-sm"><thead><tr><th>Name</th><th>Net ₹</th><th>Base units</th></tr></thead><tbody>
                @foreach ($data['breakdowns'][$key] as $row)
                    <tr><td class="py-2">{{ $row['name'] }}</td><td>{{ number_format($row['net_paise'] / 100, 2) }}</td><td>{{ $row['net_base_quantity'] }}</td></tr>
                @endforeach
            </tbody></table>
        </details>
    @endforeach
    <details><summary class="cursor-pointer font-semibold">Six-month net-secondary trend</summary>
        @foreach ($data['trends'] as $trend)
            <p class="py-1 text-sm">{{ $trend['month'] }} · ₹ {{ number_format($trend['net_paise'] / 100, 2) }}</p>
        @endforeach
    </details>
    @if ($data['rcpa_comparison'])
        <div><p class="font-semibold">Visible RCPA estimates [ESTIMATED_FIELD]</p>
            <p class="text-sm text-gray-500">Unweighted observations by product and segment. These do not change verified sales or employee credit.</p>
            @foreach ($data['rcpa_comparison'] as $estimate)
                <p class="py-2 text-sm">{{ $estimate['item_name'] }} · {{ $estimate['segment_name'] }} · {{ $estimate['observation_count'] }} observations · mean prescription share {{ number_format($estimate['mean_doctor_rx_share'], 2) }}% · daily off-take {{ number_format($estimate['estimated_daily_offtake'], 3) }} base units [ESTIMATED_FIELD]</p>
            @endforeach
        </div>
    @endif
    <p class="text-sm">Primary invoiced value: ₹ {{ number_format($data['primary_sales_paise'] / 10000000, 2) }} lakhs. This is reported separately for distributor monitoring and does not affect achievement. Legacy invoices without a territory snapshot are excluded.</p>
</div>

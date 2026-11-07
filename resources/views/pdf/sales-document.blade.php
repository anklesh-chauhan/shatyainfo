<!DOCTYPE html>
<html>
<head>
    <title>Tax Invoice</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0px; }
        .header { text-align: left; vertical-align: top; }
        .company { font-size: 24px; font-weight: bold; }
        .invoice-details { margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; vertical-align: text-top;}
        th, td { border: 1px solid black; padding: 2px 4px; text-align: left; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .total { font-weight: bold; }
        p {margin: 0px; padding: 0px; }
    </style>
</head>
<body>
    <table style="border: 1px solid black;">
        <thead>
            <tr>
                @if($organization->logo)
                    <td style="border:none; text-align:left;">
                        <img src="{{ public_path('storage/' . $organization->logo) }}" 
                            alt="{{ $organization->name }} Logo" 
                            style="max-widht: 120px; max-height: 120px; vertical-align: top;">
                    </td>
                @endif
                <td style="border:none; text-align:left;">
                    <div class="header">
                        <div class="company">{{ $organization->name }}</div>
                        @if($organization->primaryAddress())
                            <div>
                                {{ Str::title($organization->primaryAddress()?->street) }},
                                {{ Str::title($organization->primaryAddress()?->area_town) }},
                                {{ Str::title($organization->primaryAddress()?->city?->name) }},
                                {{ Str::title($organization->primaryAddress()?->state?->name) }},
                                {{ Str::title($organization->primaryAddress()?->pin_code) }}
                            </div>
                        @endif
                        <div>Email: {{ $organization->email }}</div>
                        <div>Phone: {{ $organization->phone }}</div>
                        <div>GSTIN: {{ $organization->gst_number }}</div>
                    </div>
                </td>
                <td style="border:none; text-align:right;">
                    <h2>
                        @if ($document instanceof \App\Models\Quote)
                            QUOTE
                        @elseif ($document instanceof \App\Models\SalesInvoice)
                            TAX INVOICE
                        @elseif ($document instanceof \App\Models\SalesOrder)
                            SALES ORDER
                        @else
                            DOCUMENT
                        @endif
                    </h2>
                </td>
            </tr>
        </thead>
    </table>
    <table>
        <tr>
            <td style="text-align:left; width:50%;">
                @if ($document instanceof \App\Models\Quote)
                    Quote No: {{ $document->document_number }}<br>
                    Quote Date: {{ $document->date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}<br>
                    Valid Till: {{ $document->expiration_date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}<br>
                @elseif ($document instanceof \App\Models\SalesOrder)
                    Sales Order No: {{ $document->document_number }}<br>
                    Sales Order Date: {{ $document->date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}<br>
                @elseif ($document instanceof \App\Models\SalesInvoice)
                    Invoice No: {{ $document->document_number }}<br>
                    Invoice Date: {{ $document->date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}<br>
                    Due Date: {{ $document->due_date?->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}<br>
                @endif
            </td>
            <td style="text-align:left; width:50%;">
                @php
                    $placeOfSupplyState = $document->shippingAddress?->state?->name 
                                        ?? $document->billingAddress?->state?->name;
                    $stateGstCode = $document->shippingAddress?->gstDetail?->state_code 
                                        ?? $document->billingAddress?->gstDetail?->state_code;
                @endphp

                Place Of Supply: {{ $placeOfSupplyState }} {{$stateGstCode}}<br>
                Payment Term: {{ $document->paymentTerm?->name ?? '-' }}<br>
            </td>
        </tr>
    </table>
   <table>
        <tr>
            <th style="text-align:left; font-size:16px;">Bill To</th>
            <th style="text-align:left; font-size:16px;">Ship To</th>
        </tr>
            <td style="text-align:left; width:50%;">
                <b>{{ $document->accountMaster?->name }}</b><br>
                @if($document->billingAddress)
                    {{ $document->billingAddress->street }}<br>
                    {{ $document->billingAddress->city?->name }},
                    {{ $document->billingAddress->state?->name }}
                    {{ $document->billingAddress->pin_code }}<br>
                    {{ $document->billingAddress->country?->name }}<br>
                    GSTIN: {{ $document->billingAddress->gstDetail->gst_number ?? '-' }}<br>
                    PAN: {{ $document->billingAddress->gstDetail->pan_number ?? '-' }}
                @endif
            </td>
            <td style="text-align:left; width:50%;">
                @php
                    $shipTo = $document->shippingAddress ?? $document->billingAddress;
                @endphp
                <b>{{ $document->accountMaster?->name }}</b><br>
                @if($shipTo)
                    {{ $shipTo->street }}<br>
                    {{ $shipTo->city?->name }},
                    {{ $shipTo->state?->name }}
                    {{ $shipTo->pin_code }}<br>
                    {{ $shipTo->country?->name }}<br>
                    GSTIN: {{ $shipTo->gstDetail->gst_number ?? '-' }}<br>
                    PAN: {{ $shipTo->gstDetail->pan_number ?? '-' }}
                @endif
            </td>
        </tr>
    </table>

    @php
        $discountMode = $document->discount_mode ?? null;

        $hasLineDiscount = in_array($discountMode, ['line_item', 'both'], true);

        $hasTax = $document->items->contains(fn($i) => !empty($i->tax_rate) && $i->tax_rate > 0);

        $subTotal = $document->subtotal ?? null;

        $hasTransactionDiscount = in_array($discountMode, ['transaction', 'both'], true);
        $discountType = $document->discount_type;        // "percentage" or "amount"
        $discountValue = $document->discount_value;      // raw input (20% or 500 Rs)
        $transactionDiscountAmount = $document->transaction_discount ?? 0; // final stored value

        $subTotalAfterDiscount = $subTotal - $transactionDiscountAmount;

        // Group tax details
        $taxGroups = $document->taxDetails
            ->groupBy(fn($tax) => $tax->type . ' ' . $tax->rate . '%')
            ->map(fn($group) => $group->sum('amount'));

        $totalTax = $taxGroups->sum();
        $shippingCost = $document->shipping_cost ?? 0;
        $packingForwarding = $document->packing_forwarding ?? 0;
        $insuranceCharges = $document->insurance_charges ?? 0;
        $otherCharges = $document->other_charges ?? 0;
        $grandTotal = $subTotalAfterDiscount + $totalTax + $shippingCost 
                        + $packingForwarding + $insuranceCharges + $otherCharges;
    @endphp
    
    <table>
        <tr>
            <th >#</th>
            <th>Item</th>
            <th>HSC/SAC</th>
            <th>Qty</th>
            <th>Rate</th>
            @if($hasLineDiscount)
                <th>Disc %</th>
            @endif
            @if($hasTax)
                <th>Tax %</th>
            @endif

            <th>Amount</th>
        </tr>
        <tr>
            @foreach ($document->items as $index => $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->itemMaster?->item_name ?? '-' }} <br/>
                        <i>{{ $item->description ?? '-' }}<i></td>
                    <td>{{ $item->hsn_sac ?? '-' }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->unit_price, 2) }}</td>

                    @if($hasLineDiscount)
                        <td>{{ $item->discount ? number_format($item->discount).'%' : '-' }}</td>
                    @endif

                    @if($hasTax)
                        <td>{{ $item->tax_rate ? rtrim(rtrim(number_format($item->tax_rate, 2), '0'), '.') . '%' : '-' }}</td>
                    @endif
                    <td style="text-align:right;">{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tr>
    </table>

    @php
        /**
         * Convert number to Indian currency words.
         */
        function numberToIndianCurrencyWords($number)
        {
            $no = floor($number);
            $decimal = round($number - $no, 2) * 100;
            $digits_length = strlen($no);
            $i = 0;
            $str = [];
            $words = [
                0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
                6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
                11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
                15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
                19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty',
                50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty',
                90 => 'Ninety'
            ];
            $digits = ['', 'Hundred', 'Thousand', 'Lakh', 'Crore'];
            while ($i < $digits_length) {
                $divider = ($i == 2) ? 10 : 100;
                $number_chunk = $no % $divider;
                $no = floor($no / $divider);
                $i += ($divider == 10) ? 1 : 2;
                if ($number_chunk) {
                    $plural = (($counter = count($str)) && $number_chunk > 9) ? 's' : null;
                    $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                    $str [] = ($number_chunk < 21) ? $words[$number_chunk] . " " . $digits[$counter] . $plural . " " . $hundred
                        : $words[floor($number_chunk / 10) * 10] . " " . $words[$number_chunk % 10] . " " . $digits[$counter] . $plural . " " . $hundred;
                } else $str[] = null;
            }
            $str = array_reverse($str);
            $result = implode('', $str);
            $points = ($decimal) ? " and " . $words[floor($decimal / 10) * 10] . " " . $words[$decimal % 10] . " Paise" : '';
            return 'Indian Rupees ' . trim($result) . ' Only' . $points;
        }
    @endphp
    
    <table style="width:100%; border-collapse:collapse; border:none;">
        <tr  style="border-collapse:collapse; border:none;">
            <!-- LEFT SIDE TABLE -->
            <td style="width:70%; vertical-align:top; padding:0; margin:0; border:none;">
                @if($hasTax)
                    @php
                    // Group items by HSN/SAC
                    $taxSummary = $document->items
                        ->groupBy('hsn_sac')
                        ->map(function ($group) {
                            $taxableValue = $group->sum('final_taxable_amount'); // taxable value before tax
                            $firstItem = $group->first();
                            $taxRate = $firstItem->tax_rate ?? 0;

                            // Assuming your tax split logic:
                            $cgst = $sgst = $igst = 0;

                            // Example: if intra-state (CGST + SGST), else IGST — adjust based on your system logic
                            if ($firstItem->is_intra_state ?? true) {
                                $cgst = $taxableValue * ($taxRate / 2) / 100;
                                $cgst_rate = $taxRate / 2;
                                $sgst = $taxableValue * ($taxRate / 2) / 100;
                                $sgst_rate = $taxRate / 2;
                            } else {
                                $igst = $taxableValue * ($taxRate) / 100;
                                $igst_rate = $taxRate;
                            }

                            return [
                                'hsn_sac' => $group->first()->hsn_sac ?? '-',
                                'taxable_value' => $taxableValue,
                                'cgst_rate' => $cgst_rate ?? 0,
                                'sgst_rate' => $sgst_rate ?? 0,
                                'igst_rate' => $igst_rate ?? 0,
                                'cgst' => $cgst ?? 0,
                                'sgst' => $sgst ?? 0,
                                'igst' => $igst ?? 0,
                                'total_tax' => $cgst + $sgst + $igst,
                            ];
                        });
                    @endphp
                    <table style="width:100%; border-collapse: collapse;" border="1">
                        <tr>
                            <td colspan="9" style="text-align: center; margin:0px; padding:7; font-weight:bold;">
        
                            </td>
                        </tr>
                        <tr>
                            <th rowspan="2">HSN/SAC</th>
                            <th rowspan="2">Taxable Value</th>
                            <th colspan="2">CGST</th>
                            <th colspan="2">SGST</th>
                            <th colspan="2">IGST</th>
                            <th rowspan="2">Tax Amount</th>
                        </tr>
                        <tr>
                            <th>%</th>
                            <th>Amt</th>
                            <th>%</th>
                            <th>Amt</th>
                            <th>%</th>
                            <th>Amt</th>
                        </tr>
                        <!-- DYNAMIC TAX ROWS -->
                        @foreach($taxSummary as $tax)
                            <tr>
                                <td>{{ $tax['hsn_sac'] }}</td>
                                <td style="text-align:right;">
                                    {{ number_format($tax['taxable_value'], 2) }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['cgst_rate'] > 0 ? number_format($tax['cgst_rate'], 2).'%' : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['cgst'] > 0 ? number_format($tax['cgst'], 2) : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['sgst_rate'] > 0 ? number_format($tax['sgst_rate'], 2).'%' : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['sgst'] > 0 ? number_format($tax['sgst'], 2) : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['igst_rate'] > 0 ? number_format($tax['igst_rate'], 2).'%' : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ $tax['igst'] > 0 ? number_format($tax['igst'], 2) : '0' }}
                                </td>
                                <td style="text-align:right;">
                                    {{ number_format($tax['total_tax'], 2) }}
                                </td>
                            </tr>
                        @endforeach

                        <!-- TOTAL ROW -->
                        <tr style="font-weight:bold;">
                            <td >Total</td>
                            <td style="text-align:right;">
                                {{ number_format($taxSummary->sum('taxable_value'), 2) }}
                            </td>
                            <td colspan="2" style="text-align:right;">
                                {{ number_format($taxSummary->sum('cgst'), 2) }}
                            </td>
                            <td colspan="2" style="text-align:right;">
                                {{ number_format($taxSummary->sum('sgst'), 2) }}
                            </td>
                            <td colspan="2" style="text-align:right;">
                                {{ number_format($taxSummary->sum('igst'), 2) }}
                            </td>
                            <td style="text-align:right;">
                                {{ number_format($taxSummary->sum('total_tax'), 2) }}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="9" style="text-align:left; font-size:10px;">
                                Tax Amount in Words: {{ numberToIndianCurrencyWords($totalTax) }}
                            </td>
                        </tr>
                    </table>
                @endif
            </td>

            <!-- RIGHT SIDE TABLE (Your Existing Summary Table) -->
            <td style="width:30%; vertical-align:top; padding:0; margin:0; border:none;">
                <table style="
                        width:100%;
                        border-collapse: collapse;
                        border-spacing: 0;
                        border: none;
                        margin: 0;
                        padding: 0;
                    ">
                    @php
                        // Calculate Gross Amount directly from items
                        $grossAmount = $document->items->sum('amount');
                    @endphp

                    <tr>
                        <td style="text-align:right; font-weight:bold;">Subtotal</td>
                        <td style="text-align:right;">{{ number_format($grossAmount, 2) }}</td>
                    </tr>

                    @if($hasTransactionDiscount && $transactionDiscountAmount > 0)
                        <tr>
                            <td style="text-align:right; font-weight:bold;">
                                Transaction Disc. 
                                @if($discountType === 'percentage')
                                    -{{ $discountValue }}%
                                @elseif($discountType === 'amount')
                                    -{{ number_format($discountValue, 2) }}
                                @endif

                            </td>
                            <td style="text-align:right;">-{{ number_format($transactionDiscountAmount, 2) }}</td>
                        </tr>
                    @endif

                    <tr>
                        <td style="text-align:right; font-weight:bold;">Amount After All Disc</td>
                        <td style="text-align:right;">{{ number_format($subTotal, 2) }}</td>
                    </tr>

                    @foreach($taxGroups as $label => $amount)
                        <tr>
                            <td style="text-align:right; font-weight:bold;">{{ $label }}</td>
                            <td style="text-align:right;">+{{ number_format($amount, 2) }}</td>
                        </tr>
                    @endforeach

                    @if($shippingCost > 0)
                        <tr>
                            <td style="text-align:right; font-weight:bold;">Shipping Cost</td>
                            <td style="text-align:right;">+{{ number_format($shippingCost, 2) }}</td>
                        </tr>
                    @endif

                    @if($packingForwarding > 0)
                        <tr>
                            <td style="text-align:right; font-weight:bold;">Packing & Forwarding</td>
                            <td style="text-align:right;">+{{ number_format($packingForwarding, 2) }}</td>
                        </tr>
                    @endif
                    @if($insuranceCharges > 0)
                        <tr>
                            <td style="text-align:right; font-weight:bold;">Insurance Charges</td>
                            <td style="text-align:right;">+{{ number_format($insuranceCharges, 2) }}</td>
                        </tr>
                    @endif
                    @if($otherCharges > 0)  
                        <tr>
                            <td style="text-align:right; font-weight:bold;">Other Charges</td>
                            <td style="text-align:right;">+{{ number_format($otherCharges, 2) }}</td>
                        </tr>
                    @endif

                    <tr>
                        <td style="text-align:right; font-weight:bold;">Grand Total</td>
                        <td style="text-align:right; font-weight:bold;">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div>
        <p style="margin-top:10px; font-weight:bold;">
            <b>Chargeable Amount in Words: {{ numberToIndianCurrencyWords($grandTotal) }}</b>
        </p>
    </div>
    <div>
        <br>
        <p><strong>Terms & Conditions</strong></p>
       {!! $document->termsAndCondition->content ?? 'N/A' !!}
    </div>
    
    @if ($document instanceof \App\Models\SalesInvoice)

        @php
            // $organization = $document->organization ?? \App\Models\Organization::first();
            $bankDetails = $organization?->bankDetail;
        @endphp

        <div style="margin-top: 20px;">
            <p><strong>Bank Account Details</strong></p>

            @if($bankDetails && $bankDetails->count())
                <table style="width: 100%; border-collapse: collapse; margin-top: 8px;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Bank Name</th>
                            <th style="text-align:left;">Account Name</th>
                            <th style="text-align:left;">Account Number</th>
                            <th style="text-align:left;">IFSC Code</th>
                            <th style="text-align:left;">Branch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bankDetails as $bank)
                            <tr>
                                <td>{{ $bank->bank_name }}</td>
                                <td>{{ $bank->bank_account_name }}</td>
                                <td>{{ $bank->bank_account_number }}</td>
                                <td>{{ $bank->bank_account_ifsc_code }}</td>
                                <td>{{ $bank->bank_account_branch }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    <div style="margin-top:50px;">
        <p>For <strong>{{ $organization->name }}</strong></p>
        <br><br><br>
        <p>Authorised Signatory</p>
    </div>
</body>
</html>
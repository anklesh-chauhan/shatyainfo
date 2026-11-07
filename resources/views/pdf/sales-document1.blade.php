<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ class_basename($document) }} #{{ $document->document_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        h1 { font-size: 20px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f3f3f3; }
        .totals { text-align: right; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Quotation #{{ $document->document_number }}</h1>
    <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($document->date)->format(app(\App\Services\DateFormatService::class)->dateFormat()) }}</p>
    <p><strong>Customer:</strong> {{ $document->contactDetail?->name ?? '-' }}</p>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Tax %</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $index => $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->itemMaster?->item_name ?? '-' }}</td>
                    <td>{{ $item->description ?? '-' }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->price, 2) }}</td>
                    <td>{{ $item->tax_rate }}%</td>
                    <td>{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Subtotal:</strong> {{ number_format($document->subtotal, 2) }}</p>
        <p><strong>Tax:</strong> {{ number_format($document->tax, 2) }}</p>
        <p><strong>Total:</strong> {{ number_format($document->total, 2) }}</p>
    </div>
</body>
</html>

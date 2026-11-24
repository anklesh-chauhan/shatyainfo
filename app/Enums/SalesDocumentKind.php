<?php

namespace App\Enums;

use App\Models\Quote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

enum SalesDocumentKind: string
{
    case Quote = 'quotes';
    case Order = 'sales-orders';
    case Invoice = 'sales-invoices';

    public static function forRequest(Request $request): self
    {
        return self::from(strtok($request->route()->getName(), '.'));
    }

    /** @return class-string<Quote|SalesOrder|SalesInvoice> */
    public function model(): string
    {
        return match ($this) {
            self::Quote => Quote::class,
            self::Order => SalesOrder::class,
            self::Invoice => SalesInvoice::class,
        };
    }

    public function module(): string
    {
        return class_basename($this->model());
    }

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'quote',
            self::Order => 'sales order',
            self::Invoice => 'sales invoice',
        };
    }
}

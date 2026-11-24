<?php

namespace App\Filament\Resources\DeliveryChallans\Pages;

use App\Filament\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Services\Inventory\DeliveryChallanRegister;
use App\Services\Inventory\InvoiceDeliveryHandoff;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateDeliveryChallan extends CreateRecord
{
    protected static string $resource = DeliveryChallanResource::class;

    public function mount(): void
    {
        parent::mount();
        if (! request()->filled('sales_invoice_id')) {
            return;
        }
        $handoff = app(InvoiceDeliveryHandoff::class);
        $handoff->authorizeCreate(auth()->user());
        $invoice = app(DeliveryChallanRegister::class)->orderData(auth()->user(), request()->integer('sales_invoice_id'));
        abort_if($invoice['lines'] === [], 422, 'This invoice has no quantity available to dispatch.');
        $this->data = [...$this->data, 'sales_invoice_id' => $invoice['id'], 'customer_id' => $invoice['customer_id'], 'lines' => collect($invoice['lines'])->map(fn (array $line): array => Arr::except($line, ['item']))->all()];
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(DeliveryChallanRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}

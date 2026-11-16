<?php

namespace App\Helpers;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\ContactDetail;
use App\Models\NumberSeries;
use App\Models\Quote;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDocumentLink;
use App\Services\OptionalModuleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class SalesDocumentQuickCreate
{
    /**
     * Build the default payload for a quick quote/sales order draft.
     */
    public static function buildDraftData(
        AccountMaster $accountMaster,
        ?ContactDetail $contactDetail = null,
        ?Address $billingAddress = null,
        ?User $salesPerson = null,
        string $documentClass = Quote::class,
    ): array {
        $billingAddressId = $billingAddress?->id;

        return [
            'document_number' => NumberSeries::getNextNumber($documentClass),
            'date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'INR',
            'account_master_id' => $accountMaster->id,
            'contact_detail_id' => $contactDetail?->id,
            'billing_address_id' => $billingAddressId,
            'shipping_address_id' => $billingAddressId,
            'sales_person_id' => $salesPerson?->id ?? Auth::id(),
            'description' => 'Created from today\'s visit for '.$accountMaster->name,
            'gross_total' => 0,
            'subtotal' => 0,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'transaction_discount' => 0,
            'tax' => 0,
            'round_off' => 0,
            'total' => 0,
        ];
    }

    /**
     * Create a quick quote or sales order from an existing visit.
     */
    public static function createFromVisit(Visit $visit, string $documentClass, ?User $actor = null): Model
    {
        $actor ??= Auth::user();
        abort_unless($actor && app(OptionalModuleService::class)->navigationEnabled(class_basename($documentClass)), 403);
        Gate::forUser($actor)->authorize('create', $documentClass);

        $company = $visit->primaryCompany();

        $contact = $company
            ? $company->contactDetails()->first()
            : null;

        $billingAddress = $company
            ? ($company->addresses()->where('address_type', 'Billing')->first()
                ?? $company->addresses()->first())
            : null;

        $salesPerson = $visit->employee ?: Auth::user();

        $document = $documentClass::create(
            self::buildDraftData(
                $company ?? new AccountMaster([
                    'id' => null,
                    'name' => 'Unknown customer',
                ]),
                $contact,
                $billingAddress,
                $salesPerson,
                $documentClass,
            )
        );

        // Link Visit <-> Document
        VisitDocumentLink::create([
            'visit_id' => $visit->id,
            'documentable_type' => $documentClass,
            'documentable_id' => $document->id,
        ]);

        return $document;
    }
}

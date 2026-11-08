<?php

namespace App\Filament\GlobalSearch;

use App\Models;
use BackedEnum;
use DateTimeInterface;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessSearchProvider implements GlobalSearchProvider
{
    /** @return array<class-string<Model>, array{fields: list<string>, codes: list<string>, title: list<string>, with?: list<string>, customers?: string}> */
    public function profiles(): array
    {
        return [
            Models\AccountMaster::class => ['fields' => ['account_code', 'name', 'phone_number'], 'codes' => ['account_code'], 'title' => ['name', 'account_code'], 'with' => ['addresses.city']],
            Models\ContactDetail::class => ['fields' => ['first_name', 'last_name', 'mobile_number', 'email'], 'codes' => [], 'title' => ['full_name']],
            Models\Company::class => ['fields' => ['name', 'phone_number', 'email'], 'codes' => [], 'title' => ['name'], 'with' => ['addresses.city']],
            Models\ItemMaster::class => ['fields' => ['item_code', 'sku', 'item_name'], 'codes' => ['item_code', 'sku'], 'title' => ['item_name', 'item_code']],
            Models\Lead::class => ['fields' => ['reference_code', 'accountMaster.name', 'contactDetail.first_name', 'contactDetail.last_name', 'contactDetail.company.name'], 'codes' => ['reference_code'], 'title' => ['reference_code', 'accountMaster.name', 'contactDetail.full_name', 'contactDetail.company.name']],
            Models\Deal::class => ['fields' => ['reference_code', 'deal_name', 'accountMaster.name'], 'codes' => ['reference_code'], 'title' => ['reference_code', 'deal_name', 'accountMaster.name']],
            Models\Quote::class => ['fields' => ['document_number', 'accountMaster.name'], 'codes' => ['document_number'], 'title' => ['document_number', 'accountMaster.name']],
            Models\SalesOrder::class => ['fields' => ['document_number', 'accountMaster.name'], 'codes' => ['document_number'], 'title' => ['document_number', 'accountMaster.name']],
            Models\SalesInvoice::class => ['fields' => ['document_number', 'accountMaster.name'], 'codes' => ['document_number'], 'title' => ['document_number', 'accountMaster.name']],
            Models\Visit::class => ['fields' => ['document_number'], 'codes' => ['document_number'], 'title' => ['document_number'], 'with' => ['employee', 'visitables.visitable'], 'customers' => 'visitables'],
            Models\SalesDcr::class => ['fields' => ['id', 'dcr_date', 'user.name'], 'codes' => ['id'], 'title' => ['user.name', 'dcr_date'], 'customers' => 'visits.visitables'],
            Models\SalesTourPlan::class => ['fields' => ['id', 'month', 'user.name', 'details.patches.companies.name'], 'codes' => ['id'], 'title' => ['user.name', 'month']],
            Models\PurchaseOrder::class => ['fields' => ['document_number'], 'codes' => ['document_number'], 'title' => ['document_number', 'supplier.name']],
            Models\GoodsReceiptNote::class => ['fields' => ['document_number'], 'codes' => ['document_number'], 'title' => ['document_number', 'supplier.name']],
            Models\InventoryTransfer::class => ['fields' => ['transfer_number'], 'codes' => ['transfer_number'], 'title' => ['transfer_number', 'item.item_name']],
            Models\SampleRequest::class => ['fields' => ['document_number'], 'codes' => ['document_number'], 'title' => ['document_number', 'employee.full_name']],
            Models\SampleIssue::class => ['fields' => ['document_number'], 'codes' => ['document_number'], 'title' => ['document_number', 'sampleRequest.employee.full_name']],
            Models\Employee::class => ['fields' => ['employee_id', 'first_name', 'middle_name', 'last_name'], 'codes' => ['employee_id'], 'title' => ['full_name', 'employee_id']],
        ];
    }

    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = GlobalSearchResults::make();
        $search = trim($query);
        if (! Filament::auth()->check() || mb_strlen($search) < 2) {
            return $results;
        }

        $resources = collect(Filament::getResources())->keyBy(fn (string $resource): string => $resource::getModel());
        $categories = collect();
        foreach ($this->profiles() as $model => $profile) {
            $resource = $resources->get($model);
            if (! $resource || ! $resource::canAccess()) {
                continue;
            }

            $builder = $resource::getEloquentQuery();
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $builder->whereNull($builder->getModel()->getQualifiedDeletedAtColumn());
            }
            $relations = collect($profile['title'])
                ->filter(fn (string $field): bool => str_contains($field, '.'))
                ->map(fn (string $field): string => Str::beforeLast($field, '.'))
                ->merge($profile['with'] ?? [])->unique()->values()->all();
            $builder->with($relations);

            foreach (preg_split('/\s+/u', $search, flags: PREG_SPLIT_NO_EMPTY) as $word) {
                $builder->where(function (Builder $matches) use ($profile, $word): void {
                    foreach ($profile['fields'] as $field) {
                        if (str_contains($field, '.')) {
                            $matches->orWhereHas(Str::beforeLast($field, '.'), fn (Builder $related): Builder => $this->matching($related, Str::afterLast($field, '.'), $word));
                        } else {
                            $matches->orWhere(fn (Builder $direct): Builder => $this->matching($direct, $field, $word));
                        }
                    }
                    if (isset($profile['customers'])) {
                        $matches->orWhereHas($profile['customers'], function (Builder $visitable) use ($word): void {
                            $visitable->whereHasMorph('visitable', [Models\AccountMaster::class, Models\Company::class, Models\ContactDetail::class], function (Builder $customer, string $type) use ($word): void {
                                $customer->where(function (Builder $names) use ($type, $word): void {
                                    foreach ($type === Models\ContactDetail::class ? ['first_name', 'last_name'] : ['name'] as $field) {
                                        $names->orWhere(fn (Builder $name): Builder => $this->matching($name, $field, $word));
                                    }
                                });
                            });
                        });
                    }
                });
            }

            $this->rank($builder, $profile, $search);
            $items = collect();
            $bestRank = 3;
            foreach ($builder->limit(5)->get() as $record) {
                $url = $this->recordUrl($resource, $record);
                if ($url === null) {
                    continue;
                }
                $bestRank = min($bestRank, (int) $record->getAttribute('search_rank'));
                $title = collect($profile['title'])->map(fn (string $field): ?string => $this->value(data_get($record, $field)))->filter()->unique()->implode(' — ');
                if ($record instanceof Models\SalesDcr || $record instanceof Models\SalesTourPlan) {
                    $title = ($record instanceof Models\SalesDcr ? 'DCR' : 'Tour Plan').' #'.$record->getKey().' — '.$title;
                }
                $items->push(new GlobalSearchResult(title: $title ?: $resource::getModelLabel().' #'.$record->getKey(), url: $url, details: $this->details($record)));
            }
            if ($items->isNotEmpty()) {
                $categories->push(['label' => $resource::getNavigationLabel(), 'items' => $items, 'rank' => $bestRank]);
            }
        }
        foreach ($categories->sortBy('rank') as $category) {
            $results->category($category['label'], $category['items']);
        }

        return $results;
    }

    protected function matching(Builder $query, string $field, string $search): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($field));

        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.$this->escaped($search).'%']);
    }

    protected function escaped(string $search): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
    }

    /** @param array{fields: list<string>, codes: list<string>} $profile */
    protected function rank(Builder $query, array $profile, string $search): void
    {
        $clauses = [];
        $bindings = [];
        foreach (array_unique([...$profile['codes'], ...$profile['fields']]) as $field) {
            if (str_contains($field, '.')) {
                continue;
            }
            $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($field));
            $rank = in_array($field, $profile['codes'], true) ? 0 : 1;
            $clauses[] = "WHEN LOWER({$column}) = ? THEN {$rank}";
            $bindings[] = mb_strtolower($search);
        }
        foreach ($profile['fields'] as $field) {
            if (! str_contains($field, '.')) {
                $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($field));
                $clauses[] = "WHEN LOWER({$column}) LIKE ? ESCAPE '!' THEN 2";
                $bindings[] = $this->escaped($search).'%';
            }
        }
        $query->select($query->qualifyColumn('*'))->selectRaw('CASE '.implode(' ', $clauses).' ELSE 3 END AS search_rank', $bindings)
            ->reorder()->orderBy('search_rank')->orderByDesc($query->getModel()->getQualifiedKeyName());
    }

    /** @param class-string<resource> $resource */
    protected function recordUrl(string $resource, Model $record): ?string
    {
        if ($resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }
        if ($resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }
        if ($resource::canView($record)) {
            return $resource::getUrl('index', ['tableSearch' => $record->getAttribute('document_number') ?? $record->getAttribute('reference_code') ?? $record->getAttribute('name') ?? $record->getAttribute('item_name') ?? $record->getAttribute('employee_id') ?? $record->getKey()]);
        }

        return null;
    }

    /** @return array<string, string> */
    protected function details(Model $record): array
    {
        $details = [];
        foreach (['Date' => ['date', 'visit_date', 'dcr_date', 'month', 'order_date', 'receipt_date', 'request_date', 'issue_date'], 'Status' => ['status', 'visit_status', 'approval_status'], 'Amount' => ['total', 'total_amount', 'amount'], 'Phone' => ['phone_number', 'mobile_number'], 'Email' => ['email']] as $label => $fields) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $record->getAttributes())) {
                    continue;
                }
                $value = $this->value($record->getAttribute($field));
                if ($value !== null) {
                    $details[$label] = $label === 'Amount' ? number_format((float) $value, 2) : $value;
                    break;
                }
            }
        }
        foreach (['employee', 'user'] as $relation) {
            if ($record->relationLoaded($relation) && ($person = $record->getRelation($relation))) {
                $details['Employee'] = $person->full_name ?? $person->name;
            }
        }

        if ($record->relationLoaded('addresses')) {
            $cities = $record->getRelation('addresses')->pluck('city.name')->filter()->unique();
            if ($cities->isNotEmpty()) {
                $details['City'] = $cities->take(2)->implode(', ');
            }
        }

        if ($record->relationLoaded('visitables')) {
            $customers = $record->getRelation('visitables')->map(fn (Model $link): ?string => $link->visitable?->name ?? $link->visitable?->full_name)->filter()->unique();
            if ($customers->isNotEmpty()) {
                $details['Customer'] = $customers->take(3)->implode(', ');
            }
        }

        return $details;
    }

    protected function value(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d M Y');
        }
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'getLabel') ? $value->getLabel() : Str::headline((string) $value->value);
        }

        return is_scalar($value) && filled($value) ? (string) $value : null;
    }
}

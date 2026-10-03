<?php

namespace App\Traits;

use App\Filament\GlobalSearch\RecordLabel;
use App\Models\Employee;
use App\Models\User;
use Exception;
use Filament\Actions\Action;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

trait HasSafeGlobalSearch
{
    // Define whether to search only the resource index page
    public static function searchResourceIndexOnly(): bool
    {
        return false; // Override in resource to enable index-only search
    }

    // Define resource keywords for index page search
    public static function getResourceSearchKeywords(): array
    {
        return [
            class_basename(static::class),
            Str::plural(class_basename(static::class)),
            Str::lower(class_basename(static::class)),
            Str::lower(Str::plural(class_basename(static::class))),
        ];
    }

    // Define globally searchable attributes (for record-based search)
    public static function getGloballySearchableAttributes(): array
    {
        if (static::searchResourceIndexOnly()) {
            return [];
        }

        $model = new (static::getModel());
        $table = $model->getTable();

        try {
            $columns = Schema::getColumnListing($table);
        } catch (Throwable) {
            return [];
        }

        $fallbackFields = [
            'reference_code', 'document_number', 'name', 'title', 'email', 'code',
            'first_name', 'last_name', 'module', 'reason', 'remarks', 'manager_remarks',
            'item_name', 'item_code', 'company_code', 'account_code', 'area_town', 'pin_code', 'sku',
        ];

        if (in_array('first_name', $columns, true)) {
            $fallbackFields[] = 'employee_id';
        }

        $customFields = property_exists(static::class, 'globallySearchableFields')
            ? static::$globallySearchableFields
            : [];

        $titleAttribute = static::getRecordTitleAttribute();

        if (is_string($titleAttribute) && $titleAttribute !== '' && ! str_contains($titleAttribute, '.')) {
            $customFields[] = $titleAttribute;
        }

        $searchableFields = collect(array_merge($fallbackFields, $customFields))
            ->filter(fn ($field): bool => is_string($field) && ! str_contains($field, '.') && in_array($field, $columns, true))
            ->values()
            ->all();

        $relations = property_exists(static::class, 'globalSearchRelations')
            ? static::$globalSearchRelations
            : [];

        return array_values(array_unique([
            ...$searchableFields,
            ...$relations,
            ...static::implicitRelationSearchAttributes($model),
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected static function implicitRelationSearchAttributes(Model $model): array
    {
        $attributes = [];
        $relations = [
            'employee' => [
                Employee::class => ['employee.first_name', 'employee.last_name', 'employee.employee_id'],
                User::class => ['employee.name', 'employee.email'],
            ],
            'user' => [
                User::class => ['user.name', 'user.email'],
            ],
        ];

        foreach ($relations as $relation => $relatedAttributes) {
            if (! method_exists($model, $relation)) {
                continue;
            }

            try {
                $related = $model->{$relation}()->getRelated();
            } catch (Throwable) {
                continue;
            }

            foreach ($relatedAttributes as $class => $fields) {
                if ($related instanceof $class) {
                    array_push($attributes, ...$fields);
                }
            }
        }

        return $attributes;
    }

    // Custom global search results
    public static function getGlobalSearchResults(string $search): Collection
    {
        $results = new Collection;
        $limit = static::$globalSearchResultsLimit ?? 5; // Default limit

        // Record-based search
        if (! static::searchResourceIndexOnly()) {
            $query = static::getGlobalSearchEloquentQuery();
            $searchableAttributes = static::getGloballySearchableAttributes();

            if ($searchableAttributes === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $query) use ($searchableAttributes, $search): void {
                    foreach ($searchableAttributes as $attribute) {
                        if (str_contains($attribute, '.')) {
                            $relation = Str::beforeLast($attribute, '.');
                            $column = Str::afterLast($attribute, '.');

                            $query->orWhereHas(
                                $relation,
                                fn (Builder $relatedQuery): Builder => $relatedQuery->where($column, 'like', "%{$search}%"),
                            );

                            continue;
                        }

                        $query->orWhere($attribute, 'like', "%{$search}%");
                    }
                });
            }

            $recordResults = $query
                ->limit($limit)
                ->get()
                ->map(fn ($record) => new GlobalSearchResult(
                    title: static::getGlobalSearchResultTitle($record),
                    details: static::getGlobalSearchResultDetails($record),
                    url: static::getGlobalSearchResultUrl($record),
                    actions: [] // No "View All" button for record-based results
                ));

            $results = $results->merge($recordResults);
        }

        // Resource index page search
        $resourceName = class_basename(static::class);
        $keywords = static::getResourceSearchKeywords();

        try {
            $indexUrl = static::getUrl('index');
        } catch (Exception) {
            $indexUrl = null;
        }

        if ($indexUrl && Str::contains(strtolower($resourceName.' '.implode(' ', $keywords)), strtolower($search))) {
            $results->push(new GlobalSearchResult(
                title: static::getGlobalSearchResultTitleForResource(),
                details: static::getGlobalSearchResultDetailsForResource(),
                url: $indexUrl,
                actions: [
                    Action::make('view_list')
                        ->label('View All')
                        ->url($indexUrl)
                        ->icon('heroicon-o-list-bullet'),
                ]
            ));
        }

        return $results;
    }

    // Eloquent query for global search with tenancy and permission filters
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $model = static::getModel();
        $instance = new $model;

        $table = $instance->getTable();

        $query = DB::connection('mysql')->table($table);

        $eloquentQuery = $instance->newEloquentBuilder($query);
        $eloquentQuery->setModel($instance);

        if ($user = auth()->user()) {
            $resourceClassName = class_basename(static::class);
            $resourceNameOnly = str_replace('Resource', '', $resourceClassName);
            $permissionName = "ViewAny:{$resourceNameOnly}";

            $isSuperAdmin = $user->hasAnyRole(['admin', 'general_manager', 'marketing_manager']);

            $canViewAny = false;

            if (! $isSuperAdmin) {
                try {
                    $canViewAny = $user->hasPermissionTo($permissionName);
                } catch (PermissionDoesNotExist) {
                    $canViewAny = false;
                }
            }

            if (! $isSuperAdmin && ! $canViewAny) {
                $eloquentQuery->whereRaw('1 = 0');
            }
        }

        return $eloquentQuery;
    }

    // Record-based search: Result title
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        $relatedLabel = RecordLabel::make($record);

        if (filled($relatedLabel)) {
            return $relatedLabel;
        }

        $fallbackFields = ['reference_code', 'document_number', 'name', 'title', 'email', 'first_name', 'code', 'module', 'reason', 'remarks'];

        foreach ($fallbackFields as $field) {
            $value = $record->getAttribute($field);

            if (! is_scalar($value) || blank($value)) {
                continue;
            }

            if ($field === 'first_name') {
                return trim($value.' '.($record->getAttribute('last_name') ?? ''));
            }

            return (string) $value;
        }

        return class_basename(static::class).' #'.$record->getKey();
    }

    // Record-based search: Result details
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [
            'Created At' => $record->created_at?->format('d-m-Y H:i'),
        ];

        // Add resource-specific details
        if (in_array('email', static::getGloballySearchableAttributes())) {
            $details['Email'] = $record->email;
        }
        if (in_array('amount', static::getGloballySearchableAttributes())) {
            $details['Amount'] = number_format($record->amount, 2);
        }
        if (in_array('reference_code', static::getGloballySearchableAttributes())) {
            $details['Reference Code'] = $record->reference_code;
        }

        return $details;
    }

    // Record-based search: Result URL
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        try {
            return static::getUrl(name: 'edit', parameters: ['record' => $record]);
        } catch (Exception $e) {
            return static::getUrl(name: 'index');
        }
    }

    // Resource index search: Result title
    public static function getGlobalSearchResultTitleForResource(): string|Htmlable
    {
        return static::getModelLabel();
    }

    // Resource index search: Result details
    public static function getGlobalSearchResultDetailsForResource(): array
    {
        return ['Description' => 'View all '.Str::plural(static::getModelLabel())];
    }

    // Resource index search: Result URL
    public static function getGlobalSearchResultUrlForResource(): string
    {
        return static::getUrl('index');
    }
}

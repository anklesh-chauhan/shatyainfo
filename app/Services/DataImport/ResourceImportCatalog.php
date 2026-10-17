<?php

namespace App\Services\DataImport;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use UnitEnum;

class ResourceImportCatalog
{
    /**
     * @var array<string, list<ImportableModule>>
     */
    private static array $cache = [];

    /**
     * Models whose create path has side effects outside a normal record save.
     *
     * @var list<class-string<Model>>
     */
    private const EXCLUDED_MODELS = [
        Tenant::class,
        User::class,
    ];

    /**
     * @var list<string>
     */
    private const SKIPPED_ATTRIBUTES = [
        'id',
        'password',
        'remember_token',
        'created_by',
        'updated_by',
        'deleted_by',
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'is_deleted',
    ];

    /**
     * @var list<string>
     */
    private const MATCH_CANDIDATES = [
        'code',
        'item_code',
        'sku',
        'employee_id',
        'name',
        'item_name',
        'title',
        'slug',
        'registration_number',
        'email',
        'gst_number',
    ];

    /**
     * @var list<string>
     */
    private const LOOKUP_CANDIDATES = [
        'name',
        'code',
        'title',
        'item_name',
        'item_code',
        'sku',
        'employee_id',
        'email',
        'display_name',
    ];

    /**
     * @return list<ImportableModule>
     */
    public function all(): array
    {
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('admin');

        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel($panel);
        }

        if (isset(self::$cache[$panel->getId()])) {
            return self::$cache[$panel->getId()];
        }

        $modules = [];

        foreach ($panel->getResources() as $resource) {
            $module = $this->moduleFor($resource);

            if ($module instanceof ImportableModule) {
                $modules[] = $module;
            }
        }

        usort($modules, function (ImportableModule $left, ImportableModule $right): int {
            return [$left->group, $left->label] <=> [$right->group, $right->label];
        });

        return self::$cache[$panel->getId()] = $modules;
    }

    public function find(string $resourceClass): ?ImportableModule
    {
        foreach ($this->all() as $module) {
            if ($module->resourceClass === $resourceClass) {
                return $module;
            }
        }

        return null;
    }

    public function findByModel(string $modelClass): ?ImportableModule
    {
        foreach ($this->all() as $module) {
            if ($module->modelClass === $modelClass) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function groupedOptions(): array
    {
        $user = auth()->user();
        $allowAll = $user && (
            $user->hasRole('super_admin')
            || $user->hasRole('administration_admin')
            || $user->can('AccessAllRecords')
        );

        $groups = [];

        foreach ($this->all() as $module) {
            if (! $allowAll && ! $this->resourceCanCreate($module->resourceClass)) {
                continue;
            }

            $groups[$module->group][$module->resourceClass] = $module->label;
        }

        ksort($groups);

        foreach ($groups as $group => $options) {
            asort($options);
            $groups[$group] = $options;
        }

        return $groups;
    }

    public function allows(ImportableModule $module): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('super_admin') || $user->hasRole('administration_admin') || $user->can('AccessAllRecords')) {
            return true;
        }

        return $this->resourceCanCreate($module->resourceClass);
    }

    /**
     * @param  class-string  $resource
     */
    private function resourceCanCreate(string $resource): bool
    {
        try {
            return $resource::canCreate();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string  $resource
     */
    private function moduleFor(string $resource): ?ImportableModule
    {
        try {
            $modelClass = $resource::getModel();

            if (! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
                return null;
            }

            if (in_array($modelClass, self::EXCLUDED_MODELS, true)) {
                return null;
            }

            $model = new $modelClass;
            $columns = $model instanceof Employee
                ? EmployeeOnboardingImporter::columns()
                : $this->columnsFor($model);

            if ($columns === []) {
                return null;
            }

            $group = $resource::getNavigationGroup();
            $groupLabel = $group instanceof BackedEnum
                ? (string) $group->value
                : (is_string($group) && $group !== '' ? $group : 'Other');

            $label = $resource::getNavigationLabel() ?: $resource::getPluralModelLabel();

            return new ImportableModule(
                resourceClass: $resource,
                modelClass: $modelClass,
                label: (string) $label,
                group: $groupLabel,
                columns: $columns,
                matchAttribute: $this->matchAttribute($columns),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<ImportColumnDefinition>
     */
    private function columnsFor(Model $model): array
    {
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            return [];
        }

        $schemaColumns = collect(Schema::getColumns($table))->keyBy('name');
        $attributes = $model->getFillable();

        if ($attributes === []) {
            $attributes = $schemaColumns->keys()->all();
        }

        $columns = [];

        foreach ($attributes as $attribute) {
            if (! is_string($attribute) || $this->skipsAttribute($attribute)) {
                continue;
            }

            $meta = $schemaColumns->get($attribute);

            if (! is_array($meta)) {
                continue;
            }

            $relation = $this->belongsToFor($model, $attribute);
            $required = ! (bool) ($meta['nullable'] ?? true)
                && ($meta['default'] ?? null) === null
                && ! (bool) ($meta['auto_increment'] ?? false);

            if ($relation instanceof BelongsTo) {
                $related = $relation->getRelated();
                $method = Str::camel(Str::beforeLast($attribute, '_id'));
                $header = Str::snake($method);
                $lookup = $this->lookupAttribute($related);

                $columns[] = new ImportColumnDefinition(
                    header: $header,
                    attribute: $attribute,
                    label: Str::headline($header),
                    type: 'relationship',
                    required: $required,
                    relationship: $method,
                    relatedModel: $related::class,
                    relatedLookup: $lookup,
                    example: 'Example',
                );

                continue;
            }

            $type = $this->typeFor($model, $attribute, $meta);
            $enumClass = $type === 'enum' ? $model->getCasts()[$attribute] : null;

            $columns[] = new ImportColumnDefinition(
                header: $attribute,
                attribute: $attribute,
                label: Str::headline($attribute),
                type: $type,
                required: $required,
                enumClass: is_string($enumClass) ? $enumClass : null,
                example: $this->exampleFor($attribute, $type, is_string($enumClass) ? $enumClass : null),
            );
        }

        return $columns;
    }

    /**
     * @param  list<ImportColumnDefinition>  $columns
     */
    private function matchAttribute(array $columns): ?string
    {
        $direct = [];

        foreach ($columns as $column) {
            if ($column->relationship === null && $column->type === 'string') {
                $direct[$column->attribute] = true;
            }
        }

        foreach (self::MATCH_CANDIDATES as $candidate) {
            if (isset($direct[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function belongsToFor(Model $model, string $attribute): ?BelongsTo
    {
        if (! str_ends_with($attribute, '_id')) {
            return null;
        }

        $method = Str::camel(Str::beforeLast($attribute, '_id'));

        if (! method_exists($model, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($model, $method);

        if ($reflection->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $declaring = $reflection->getDeclaringClass()->getName();

        if ($declaring === Model::class || str_starts_with($declaring, 'Illuminate\\Database\\Eloquent\\')) {
            return null;
        }

        $returnType = $reflection->getReturnType();

        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            $returnName = $returnType->getName();

            if (! is_a($returnName, BelongsTo::class, true)) {
                return null;
            }
        }

        try {
            $relation = $model->{$method}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof BelongsTo || $relation->getForeignKeyName() !== $attribute) {
            return null;
        }

        return $relation;
    }

    private function lookupAttribute(Model $related): string
    {
        $fillable = $related->getFillable();

        foreach (self::LOOKUP_CANDIDATES as $candidate) {
            if (in_array($candidate, $fillable, true) || Schema::hasColumn($related->getTable(), $candidate)) {
                return $candidate;
            }
        }

        return $related->getKeyName();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function typeFor(Model $model, string $attribute, array $meta): string
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        if (is_string($cast)) {
            if (in_array($cast, ['bool', 'boolean'], true)) {
                return 'boolean';
            }

            if (str_starts_with($cast, 'decimal') || str_starts_with($cast, 'float') || str_starts_with($cast, 'double')) {
                return 'number';
            }

            if (in_array($cast, ['int', 'integer'], true)) {
                return 'integer';
            }

            if (str_contains($cast, 'datetime') || str_contains($cast, 'timestamp')) {
                return 'datetime';
            }

            if (str_contains($cast, 'date')) {
                return 'date';
            }

            if (in_array($cast, ['array', 'json', 'collection', 'encrypted:array', 'encrypted:json'], true) || str_contains($cast, 'json')) {
                return 'json';
            }

            if (enum_exists($cast)) {
                return 'enum';
            }
        }

        $typeName = strtolower((string) ($meta['type_name'] ?? ''));

        return match (true) {
            $typeName === 'date' => 'date',
            in_array($typeName, ['datetime', 'timestamp'], true) => 'datetime',
            in_array($typeName, ['decimal', 'float', 'double', 'numeric'], true) => 'number',
            in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => 'integer',
            $typeName === 'json' => 'json',
            default => 'string',
        };
    }

    private function exampleFor(string $attribute, string $type, ?string $enumClass): string
    {
        if ($type === 'enum' && is_string($enumClass) && enum_exists($enumClass)) {
            $case = $enumClass::cases()[0] ?? null;

            if ($case instanceof BackedEnum) {
                return (string) $case->value;
            }

            if ($case instanceof UnitEnum) {
                return $case->name;
            }
        }

        if (str_contains($attribute, 'email')) {
            return 'name@example.test';
        }

        return match ($type) {
            'boolean' => 'yes',
            'date' => '2026-01-15',
            'datetime' => '2026-01-15 09:30:00',
            'number' => '10.50',
            'integer' => '1',
            'json' => '{"key":"value"}',
            default => 'Example',
        };
    }

    private function skipsAttribute(string $attribute): bool
    {
        if (in_array($attribute, self::SKIPPED_ATTRIBUTES, true)) {
            return true;
        }

        $lower = strtolower($attribute);

        return str_contains($lower, 'password')
            || str_contains($lower, 'token')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'created_by')
            || str_contains($lower, 'updated_by')
            || str_contains($lower, 'deleted_by');
    }
}

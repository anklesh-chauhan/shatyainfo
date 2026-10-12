<?php

namespace App\Services\DataImport;

final class ImportableModule
{
    /**
     * @param  list<ImportColumnDefinition>  $columns
     */
    public function __construct(
        public string $resourceClass,
        public string $modelClass,
        public string $label,
        public string $group,
        public array $columns,
        public ?string $matchAttribute,
    ) {}
}

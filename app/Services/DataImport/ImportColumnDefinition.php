<?php

namespace App\Services\DataImport;

final class ImportColumnDefinition
{
    public function __construct(
        public string $header,
        public string $attribute,
        public string $label,
        public string $type,
        public bool $required,
        public ?string $relationship = null,
        public ?string $relatedModel = null,
        public ?string $relatedLookup = null,
        public ?string $enumClass = null,
        public string $example = 'Example',
    ) {}

    /**
     * @return list<string>
     */
    public function acceptedHeaders(): array
    {
        return array_values(array_unique([$this->header, $this->attribute]));
    }
}

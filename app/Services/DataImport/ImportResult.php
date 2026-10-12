<?php

namespace App\Services\DataImport;

final class ImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $failed
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public array $failed = [],
    ) {}

    public function importedCount(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * @return array{created: int, updated: int, failed: list<array{row: int, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed,
        ];
    }
}

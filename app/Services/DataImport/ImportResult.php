<?php

namespace App\Services\DataImport;

final class ImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $failed
     * @param  list<array{employee: string, position: string, reports_to_position: string, manager: string, status: string}>  $hierarchy
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public array $failed = [],
        public array $hierarchy = [],
    ) {}

    public function importedCount(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * @return array{created: int, updated: int, failed: list<array{row: int, message: string}>, hierarchy: array}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'hierarchy' => $this->hierarchy,
        ];
    }
}

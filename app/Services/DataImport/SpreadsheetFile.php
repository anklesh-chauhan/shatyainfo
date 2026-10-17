<?php

namespace App\Services\DataImport;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

class SpreadsheetFile
{
    public function toCsv(string $path, ?string $preferredSheet = null): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'employee-import-');
        $stream = fopen($temporary, 'w');
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
            $hasPreferredSheet = false;
            $sheetCount = 0;
            $firstSheetName = null;
            if ($preferredSheet !== null) {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $sheetCount++;
                    $firstSheetName ??= $sheet->getName();
                    if ($sheet->getName() === $preferredSheet) {
                        $hasPreferredSheet = true;
                        break;
                    }
                }
                if (! $hasPreferredSheet && ($sheetCount > 1 || in_array($firstSheetName, ['Instructions', 'Examples'], true))) {
                    throw new ImportRowException('Add or restore the Employees sheet. Instructions and Examples cannot be imported.');
                }
            }
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($hasPreferredSheet && $sheet->getName() !== $preferredSheet) {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value, $row->toArray());
                    fputcsv($stream, $values, ',', '"', '');
                }
                break;
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            unlink($temporary);
            throw $exception;
        } finally {
            $reader->close();
        }
        fclose($stream);

        return $temporary;
    }
}

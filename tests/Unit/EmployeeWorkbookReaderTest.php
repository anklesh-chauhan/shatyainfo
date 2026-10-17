<?php

use App\Services\DataImport\ImportRowException;
use App\Services\DataImport\SpreadsheetFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

it('never falls back to instructions or examples when the Employees sheet is missing', function (array $names) {
    $path = sys_get_temp_dir().'/employee-reader-'.bin2hex(random_bytes(8)).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($names as $index => $name) {
        $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $writer->addRow(Row::fromValues(['employee_id', 'first_name']));
        $writer->addRow(Row::fromValues(['EXAMPLE', 'Example']));
    }
    $writer->close();
    try {
        expect(fn () => (new SpreadsheetFile)->toCsv($path, 'Employees'))->toThrow(ImportRowException::class, 'Employees sheet');
    } finally {
        unlink($path);
    }
})->with([
    'examples only' => [['Examples']],
    'instructions only' => [['Instructions']],
    'renamed upload sheet' => [['Team', 'Instructions', 'Examples']],
]);

it('continues accepting a legacy single-sheet employee workbook', function () {
    $path = sys_get_temp_dir().'/employee-reader-'.bin2hex(random_bytes(8)).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['employee_id', 'first_name']));
    $writer->addRow(Row::fromValues(['EMP001', 'Asha']));
    $writer->close();
    $csv = null;
    try {
        $csv = (new SpreadsheetFile)->toCsv($path, 'Employees');
        expect(file_get_contents($csv))->toContain('EMP001,Asha');
    } finally {
        unlink($path);
        if ($csv !== null) {
            unlink($csv);
        }
    }
});

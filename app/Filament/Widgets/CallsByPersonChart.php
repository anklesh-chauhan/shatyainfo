<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;

class CallsByPersonChart extends FieldReportChart
{
    protected static string $widgetKey = 'calls_by_person';

    protected ?string $heading = 'Calls by person';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $summary = app(FieldVisualReport::class)->callsByEmployee($this->reportUser(), $this->periodKey());

        return [
            'datasets' => [
                [
                    'label' => 'Completed calls',
                    'data' => $summary['data'],
                    'backgroundColor' => '#2563eb',
                ],
            ],
            'labels' => $summary['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return $this->barOptions(horizontal: true);
    }
}

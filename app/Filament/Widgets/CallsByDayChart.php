<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;

class CallsByDayChart extends FieldReportChart
{
    protected static string $widgetKey = 'calls_by_day';

    protected ?string $heading = 'Calls by day';

    protected ?string $maxHeight = '18rem';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $series = app(FieldVisualReport::class)->callsByDay($this->reportUser(), $this->periodKey());

        return [
            'datasets' => [
                [
                    'label' => 'Completed',
                    'data' => $series['completed'],
                    'backgroundColor' => '#16a34a',
                ],
                [
                    'label' => 'Open',
                    'data' => $series['open'],
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'Cancelled',
                    'data' => $series['cancelled'],
                    'backgroundColor' => '#94a3b8',
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return $this->barOptions(stacked: true);
    }
}

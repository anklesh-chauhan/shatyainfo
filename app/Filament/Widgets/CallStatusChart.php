<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;

class CallStatusChart extends FieldReportChart
{
    protected static string $widgetKey = 'call_status';

    protected ?string $heading = 'Call status';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $slices = app(FieldVisualReport::class)->callStatus($this->reportUser(), $this->periodKey());

        return [
            'datasets' => [
                [
                    'label' => 'Calls',
                    'data' => $slices['data'],
                    'backgroundColor' => $slices['colors'],
                ],
            ],
            'labels' => $slices['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return $this->doughnutOptions();
    }
}

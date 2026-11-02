<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;

class DcrStatusChart extends FieldReportChart
{
    protected static string $widgetKey = 'dcr_status';

    protected ?string $heading = 'DCR status';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $slices = app(FieldVisualReport::class)->dcrStatus($this->reportUser(), $this->periodKey());

        return [
            'datasets' => [
                [
                    'label' => 'DCRs',
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

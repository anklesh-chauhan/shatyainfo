<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;

class PlanAndCallsChart extends FieldReportChart
{
    protected static string $widgetKey = 'plan_and_calls';

    protected ?string $heading = 'Plan and calls';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $summary = app(FieldVisualReport::class)->planAndCalls($this->reportUser(), $this->periodKey());

        return [
            'datasets' => [
                [
                    'label' => 'Count',
                    'data' => $summary['data'],
                    'backgroundColor' => ['#2563eb', '#16a34a', '#f59e0b'],
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
        return $this->barOptions();
    }
}

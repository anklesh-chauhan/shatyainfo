<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;
use Illuminate\Contracts\Support\Htmlable;

class AverageCallsChart extends FieldReportChart
{
    protected static string $widgetKey = 'average_calls';

    protected ?string $heading = 'Average calls';

    /**
     * @var array{labels: list<string>, data: list<float>, team_average: float, days: int}|null
     */
    private ?array $summary = null;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): string|Htmlable|null
    {
        $period = app(FieldVisualReport::class)->period($this->periodKey())['label'];
        $average = number_format($this->summary()['team_average'], 1);

        return $period.' · '.$average.' completed / day';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $summary = $this->summary();

        return [
            'datasets' => [
                [
                    'label' => 'Completed / day',
                    'data' => $summary['data'],
                    'backgroundColor' => '#0ea5e9',
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
        $options = $this->barOptions(horizontal: true);
        $options['scales']['x']['ticks']['precision'] = 1;

        return $options;
    }

    /**
     * @return array{labels: list<string>, data: list<float>, team_average: float, days: int}
     */
    private function summary(): array
    {
        return $this->summary ??= app(FieldVisualReport::class)->averageCalls($this->reportUser(), $this->periodKey());
    }
}

<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FieldVisualReport;
use Illuminate\Contracts\Support\Htmlable;

class ExpenseByTypeChart extends FieldReportChart
{
    protected static string $widgetKey = 'expenses';

    protected ?string $heading = 'Field expenses';

    /**
     * @var array{labels: list<string>, data: list<float>, total: float}|null
     */
    private ?array $summary = null;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): string|Htmlable|null
    {
        $period = app(FieldVisualReport::class)->period($this->periodKey())['label'];

        return $period.' · ₹'.number_format($this->summary()['total'], 0).' claimed';
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
                    'label' => 'Claimed',
                    'data' => $summary['data'],
                    'backgroundColor' => '#0f766e',
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
        $options['scales']['x']['ticks']['precision'] = 0;

        return $options;
    }

    /**
     * @return array{labels: list<string>, data: list<float>, total: float}
     */
    private function summary(): array
    {
        return $this->summary ??= app(FieldVisualReport::class)->expensesByType($this->reportUser(), $this->periodKey());
    }
}

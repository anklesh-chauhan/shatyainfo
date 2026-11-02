<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Reports\FieldVisualReport;
use App\Support\DashboardLayout;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

abstract class FieldReportChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static string $widgetKey = '';

    protected ?string $maxHeight = '16rem';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return app(DashboardLayout::class)->enabled(static::$widgetKey);
    }

    public function getDescription(): string|Htmlable|null
    {
        return app(FieldVisualReport::class)->period($this->pageFilters['period'] ?? null)['label'];
    }

    protected function reportUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    protected function periodKey(): ?string
    {
        $period = $this->pageFilters['period'] ?? null;

        return is_string($period) ? $period : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function barOptions(bool $stacked = false, bool $horizontal = false): array
    {
        return [
            'indexAxis' => $horizontal ? 'y' : 'x',
            'plugins' => [
                'legend' => [
                    'display' => $stacked,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => $stacked,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
                'y' => [
                    'stacked' => $stacked,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function doughnutOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\Visit;
use App\Services\SalespersonAi\PreCallBriefingService;
use Filament\Widgets\Widget;

class VisitPreCallBriefingWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.visit-pre-call-briefing';

    protected int|string|array $columnSpan = 'full';

    public int $visitId;

    /**
     * @return array<string, mixed>
     */
    public function briefing(): array
    {
        $visit = Visit::query()->find($this->visitId);

        if (! $visit) {
            return [
                'summary' => 'This visit could not be loaded.',
                'last_visits' => [],
                'open_follow_ups' => [],
                'samples' => ['suggestions' => [], 'warnings' => []],
            ];
        }

        return app(PreCallBriefingService::class)->forVisit($visit);
    }
}

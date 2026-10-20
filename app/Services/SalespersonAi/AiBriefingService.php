<?php

namespace App\Services\SalespersonAi;

use App\Models\Visit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiBriefingService
{
    public function __construct(
        private AiProviderClient $client,
        private AiSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $briefing
     * @return array<string, mixed>
     */
    public function enrich(Visit $visit, array $briefing): array
    {
        $briefing['ai'] = ['status' => 'disabled', 'source_ids' => []];
        try {
            $configuration = $this->settings->configuration();
        } catch (Throwable) {
            $briefing['ai']['status'] = 'unavailable';

            return $briefing;
        }
        if (! $configuration['enabled']) {
            return $briefing;
        }

        $scope = $this->settings->scope();
        $provider = $configuration['provider'];
        $providerSettings = $configuration['providers'][$provider] ?? [];
        if (! in_array($provider, ['openai', 'anthropic', 'ollama'], true)
            || ($provider !== 'ollama' && blank($providerSettings['api_key'] ?? null))
            || blank($providerSettings['model'] ?? null)) {
            $briefing['ai']['status'] = 'not_configured';

            return $briefing;
        }

        $facts = $this->facts($briefing);
        if ($facts === []) {
            $briefing['ai']['status'] = 'no_context';

            return $briefing;
        }

        $started = microtime(true);
        $status = 'unavailable';
        $usage = [];
        try {
            $input = json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($input) > (int) $configuration['max_input_bytes']) {
                $status = 'context_too_large';
                $briefing['ai']['status'] = $status;

                return $briefing;
            }
            $key = 'salesperson-ai:'.$scope.':'.$visit->employee_id.':'.$visit->getKey().':'
                .hash('sha256', 'briefing-v1'.$provider.json_encode($providerSettings, JSON_THROW_ON_ERROR).$input);
            $selection = Cache::get($key);
            if ($selection === null) {
                if (! $this->reserveRequest($scope, (int) $visit->employee_id, $configuration)) {
                    $status = 'limited';
                    $briefing['ai']['status'] = $status;

                    return $briefing;
                }
                $selection = $this->client->selectFacts($provider, $facts, $configuration);
                $usage = [
                    'input_tokens' => $selection['input_tokens'],
                    'output_tokens' => $selection['output_tokens'],
                ];
                Cache::put($key, $selection, now()->addMinutes(5));
                $status = 'assisted';
            } else {
                $status = 'cached';
            }

            $ids = $selection['fact_ids'];
            $briefing['ai'] = ['status' => $status, 'source_ids' => $ids];
            $briefing['ai_highlights'] = array_map(fn (string $id): string => $facts[$id], $ids);
        } catch (Throwable) {
            $status = 'unavailable';
            $briefing['ai']['status'] = $status;
        } finally {
            Log::info('salesperson_ai.briefing', [
                'scope' => $scope,
                'user_id' => $visit->employee_id,
                'provider' => $provider,
                'model' => $providerSettings['model'],
                'prompt_version' => 'briefing-v1',
                'status' => $status,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                ...$usage,
            ]);
        }

        return $briefing;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function reserveRequest(string $scope, int $userId, array $configuration): bool
    {
        return (bool) Cache::lock('salesperson-ai:budget:'.$scope, 5)->get(function () use ($scope, $userId, $configuration): bool {
            $dailyKey = 'salesperson-ai:daily:'.$scope.':'.now()->format('Y-m-d');
            $minuteKey = 'salesperson-ai:minute:'.$scope.':'.$userId.':'.now()->format('Y-m-d-H-i');
            $daily = (int) Cache::get($dailyKey, 0);
            $minute = (int) Cache::get($minuteKey, 0);
            if ($daily >= (int) $configuration['daily_requests']
                || $minute >= (int) $configuration['user_requests_per_minute']) {
                return false;
            }
            Cache::put($dailyKey, $daily + 1, now()->addDays(2));
            Cache::put($minuteKey, $minute + 1, now()->addMinutes(2));

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $briefing
     * @return array<string, string>
     */
    private function facts(array $briefing): array
    {
        $facts = [];
        foreach ($briefing['last_visits'] ?? [] as $visit) {
            $text = 'Recorded visit on '.($visit['visit_date'] ?? 'an unknown date').'.';
            if (filled($visit['outcome'] ?? null)) {
                $text .= ' Recorded outcome: '.$visit['outcome'].'.';
            }
            if (filled($visit['remarks'] ?? null)) {
                $text .= ' Recorded remarks: '.$visit['remarks'];
            }
            $facts['visit:'.$visit['id']] = $text;
        }
        foreach ($briefing['open_follow_ups'] ?? [] as $followUp) {
            $text = ($followUp['overdue'] ? 'Overdue follow-up' : 'Open follow-up')
                .', due '.($followUp['next_follow_up_date'] ?? 'date not recorded').'.';
            if (filled($followUp['interaction'] ?? null)) {
                $text .= ' Recorded interaction: '.$followUp['interaction'];
            }
            $facts['follow_up:'.$followUp['id']] = $text;
        }

        return $facts;
    }
}

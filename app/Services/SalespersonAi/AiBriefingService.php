<?php

namespace App\Services\SalespersonAi;

use App\Models\Tenant;
use App\Models\Visit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiBriefingService
{
    public function __construct(private AiProviderClient $client) {}

    /**
     * @param  array<string, mixed>  $briefing
     * @return array<string, mixed>
     */
    public function enrich(Visit $visit, array $briefing): array
    {
        $briefing['ai'] = ['status' => 'disabled', 'source_ids' => []];
        if (! config('salesperson_ai.enabled')) {
            return $briefing;
        }

        $tenantId = Tenant::current()?->getKey();
        $scope = $tenantId === null ? 'standalone' : (string) $tenantId;
        $allowed = array_map('strval', config('salesperson_ai.allowed_tenants', []));
        if (($tenantId === null && ! config('salesperson_ai.allow_standalone'))
            || ($tenantId !== null && ! in_array($scope, $allowed, true))) {
            return $briefing;
        }

        $provider = config("salesperson_ai.tenant_providers.{$scope}", config('salesperson_ai.provider'));
        if (! in_array($provider, ['openai', 'anthropic'], true)
            || blank(config("salesperson_ai.providers.{$provider}.api_key"))
            || blank(config("salesperson_ai.providers.{$provider}.model"))) {
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
            if (strlen($input) > (int) config('salesperson_ai.max_input_bytes', 12000)) {
                $status = 'context_too_large';
                $briefing['ai']['status'] = $status;

                return $briefing;
            }
            $model = config("salesperson_ai.providers.{$provider}.model");
            $key = 'salesperson-ai:'.$scope.':'.$visit->employee_id.':'.$visit->getKey().':'
                .hash('sha256', 'briefing-v1'.$provider.$model.$input);
            $selection = Cache::get($key);
            if ($selection === null) {
                if (! $this->reserveRequest($scope, (int) $visit->employee_id)) {
                    $status = 'limited';
                    $briefing['ai']['status'] = $status;

                    return $briefing;
                }
                $selection = $this->client->selectFacts($provider, $facts);
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
                'model' => config("salesperson_ai.providers.{$provider}.model"),
                'prompt_version' => 'briefing-v1',
                'status' => $status,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                ...$usage,
            ]);
        }

        return $briefing;
    }

    private function reserveRequest(string $scope, int $userId): bool
    {
        return (bool) Cache::lock('salesperson-ai:budget:'.$scope, 5)->get(function () use ($scope, $userId): bool {
            $dailyKey = 'salesperson-ai:daily:'.$scope.':'.now()->format('Y-m-d');
            $minuteKey = 'salesperson-ai:minute:'.$scope.':'.$userId.':'.now()->format('Y-m-d-H-i');
            $daily = (int) Cache::get($dailyKey, 0);
            $minute = (int) Cache::get($minuteKey, 0);
            if ($daily >= (int) config('salesperson_ai.daily_requests', 1000)
                || $minute >= (int) config('salesperson_ai.user_requests_per_minute', 10)) {
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

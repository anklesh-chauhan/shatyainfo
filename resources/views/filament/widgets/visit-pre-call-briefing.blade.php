<div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    @php($briefing = $this->briefing())

    <p class="text-sm font-semibold text-gray-950 dark:text-white">Pre-call briefing</p>
    <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $briefing['summary'] }}</p>

    @if (($briefing['ai_highlights'] ?? []) !== [])
        <p class="mt-2 text-sm font-semibold">AI-selected highlights from recorded history</p>
        <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($briefing['ai_highlights'] as $highlight)
                <li>{{ $highlight }}</li>
            @endforeach
        </ul>
    @endif

    @if (($briefing['open_follow_ups'] ?? []) !== [])
        <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($briefing['open_follow_ups'] as $followUp)
                <li>
                    Follow-up {{ $followUp['next_follow_up_date'] ?? 'with no date' }}
                    @if ($followUp['overdue'])
                        is overdue.
                    @endif
                    @if (filled($followUp['interaction']))
                        {{ $followUp['interaction'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (($briefing['samples']['warnings'] ?? []) !== [])
        <ul class="mt-3 space-y-1 text-sm text-danger-600 dark:text-danger-400">
            @foreach ($briefing['samples']['warnings'] as $warning)
                <li>{{ $warning['message'] }}</li>
            @endforeach
        </ul>
    @endif
</div>

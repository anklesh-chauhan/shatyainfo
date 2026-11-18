<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMobileQuickLinksRequest;
use App\Models\User;
use App\Services\Mobile\MobileNavigationRegistry;
use App\Services\Mobile\MobileNavigationService;
use App\Support\TopbarLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileQuickLinksController extends Controller
{
    public function __construct(
        private TopbarLinks $links,
        private MobileNavigationService $navigation,
        private MobileNavigationRegistry $registry,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->response($user, $this->catalog($user));
    }

    public function update(UpdateMobileQuickLinksRequest $request): JsonResponse
    {
        $actor = $request->user();
        $requested = $request->validated('links');
        $catalog = $this->catalog($actor);

        return $actor->getConnection()->transaction(function () use ($actor, $catalog, $requested): JsonResponse {
            $user = $actor->newQuery()->lockForUpdate()->findOrFail($actor->id);
            $links = null;

            if ($requested !== null) {
                $byKey = array_column($catalog, 'source', 'key');
                if (array_diff($requested, array_keys($byKey)) !== []) {
                    throw ValidationException::withMessages(['links' => 'Choose shortcuts from the available mobile modules.']);
                }

                $stored = $user->topbar_links ?? $this->links->selected($user, $catalog);
                $hidden = array_diff(array_unique($stored), array_values($byKey));
                if (count($requested) + count($hidden) > TopbarLinks::MAX_LINKS) {
                    throw ValidationException::withMessages(['links' => 'Remove a shortcut before adding another.']);
                }

                $remaining = array_map(fn (string $key): string => $byKey[$key], $requested);
                $links = [];
                foreach (array_unique($stored) as $source) {
                    if (in_array($source, $hidden, true)) {
                        $links[] = $source;
                    } elseif ($remaining !== []) {
                        $links[] = array_shift($remaining);
                    }
                }
                $links = [...$links, ...$remaining];
            }

            $user->forceFill(['topbar_links' => $links])->save();

            return $this->response($user, $catalog);
        });
    }

    /** @return array<class-string, array{key: string, label: string, source: class-string}> */
    private function catalog(User $user): array
    {
        $groups = $this->navigation->forUser($user, standalone: true)['groups'];
        $eligible = collect($groups)->flatMap(fn (array $group): array => $group['items'])->keyBy('key');
        $catalog = [];
        foreach ($this->registry->entries(adminOrder: true) as $entry) {
            if ($eligible->has($entry['key'])) {
                $catalog[$entry['source']] = [
                    'source' => $entry['source'],
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                ];
            }
        }

        return $catalog;
    }

    private function response(User $user, array $catalog): JsonResponse
    {
        $stored = $user->topbar_links ?? $this->links->selected($user, $catalog);
        $hidden = array_diff(array_unique($stored), array_keys($catalog));

        return response()->json(['data' => [
            'options' => array_values(array_map(fn (array $entry): array => [
                'key' => $entry['key'], 'label' => $entry['label'],
            ], $catalog)),
            'selected' => array_map(fn (string $source): string => $catalog[$source]['key'], $this->links->selected($user, $catalog)),
            'limit' => max(0, TopbarLinks::MAX_LINKS - count($hidden)),
        ]]);
    }
}

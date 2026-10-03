<?php

namespace App\Services\Field;

use App\Models\ImageTag;
use App\Models\Media;
use App\Models\User;
use App\Models\Visit;
use App\Services\Visit\VisitEnforcementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldVisitService
{
    public function __construct(
        private VisitEnforcementService $enforcement,
    ) {}

    public function findOwned(User $user, int $visitId): Visit
    {
        $visit = Visit::query()
            ->with(['territory', 'patch', 'media.tags', 'visitables.visitable', 'sgipDistribution.items'])
            ->find($visitId);

        if (! $visit || (int) $visit->employee_id !== (int) $user->id) {
            abort(404, 'Visit not found.');
        }

        return $visit;
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string}  $data
     */
    public function checkIn(User $user, Visit $visit, array $data): Visit
    {
        $this->assertOwned($user, $visit);

        if ($visit->start_time) {
            throw ValidationException::withMessages([
                'visit' => 'Visit already checked in.',
            ]);
        }

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $this->enforcement->validateCheckIn($latitude, $longitude);
        $recordedAt = FieldRecordedAt::resolve($data);
        if (isset($data['recorded_at']) && $visit->visit_date?->toDateString() !== $recordedAt->toDateString()) {
            throw ValidationException::withMessages(['recorded_at' => 'Capture date must match the visit date.']);
        }

        $visit->update([
            'start_time' => $recordedAt,
            'checkin_latitude' => $latitude,
            'checkin_longitude' => $longitude,
            'visit_status' => 'started',
        ]);

        return $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string}  $data
     * @return array{visit: Visit, needs_checkout_image: bool}
     */
    public function checkOut(User $user, Visit $visit, array $data): array
    {
        $this->assertOwned($user, $visit);

        if ($visit->end_time && $visit->visit_status === 'completed') {
            throw ValidationException::withMessages([
                'visit' => 'Visit already checked out.',
            ]);
        }

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $recordedAt = FieldRecordedAt::resolve($data);
        if ($visit->start_time && $recordedAt->lessThan($visit->start_time)) {
            throw ValidationException::withMessages(['recorded_at' => 'Check-out cannot be before check-in.']);
        }
        $this->enforcement->validateCheckOut($visit, $latitude, $longitude, $recordedAt);

        $visit->update([
            'end_time' => $recordedAt,
            'checkout_latitude' => $latitude,
            'checkout_longitude' => $longitude,
            'visit_status' => 'checked_out_pending',
        ]);

        $visit = $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']);

        if ($this->enforcement->needsCheckOutImage($visit)) {
            return [
                'visit' => $visit,
                'needs_checkout_image' => true,
            ];
        }

        $visit->update(['visit_status' => 'completed']);

        return [
            'visit' => $visit->fresh(['territory', 'patch', 'media.tags', 'visitables.visitable']),
            'needs_checkout_image' => false,
        ];
    }

    /**
     * @param  array{tag: string, latitude: float|int|string, longitude: float|int|string, captured_at?: string|null}  $data
     */
    public function storePhoto(User $user, Visit $visit, UploadedFile $photo, array $data): Media
    {
        $this->assertOwned($user, $visit);

        $tag = $data['tag'];
        $this->ensureImageTag($tag);

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        return DB::transaction(function () use ($visit, $photo, $tag, $latitude, $longitude, $data): Media {
            $path = $photo->store('visits/photos', 'public');

            $media = $visit->media()->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $photo->getClientMimeType(),
                'size' => $photo->getSize(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'captured_at' => $data['captured_at'] ?? now(),
            ]);

            $media->attachTagBySlug($tag);

            if ($tag === 'check-out' && $visit->visit_status === 'checked_out_pending') {
                $visit->update(['visit_status' => 'completed']);
            }

            return $media->fresh('tags');
        });
    }

    private function assertOwned(User $user, Visit $visit): void
    {
        if ((int) $visit->employee_id !== (int) $user->id) {
            abort(404, 'Visit not found.');
        }
    }

    private function ensureImageTag(string $slug): void
    {
        ImageTag::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => str($slug)->replace('-', ' ')->title()->toString(),
                'is_active' => true,
            ],
        );
    }
}

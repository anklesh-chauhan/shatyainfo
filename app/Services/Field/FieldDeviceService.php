<?php

namespace App\Services\Field;

use App\Models\FieldDevice;
use App\Models\SalesDcr;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registers field clients and the dates on which each still holds unsynchronized work,
 * so a DCR cannot close while a different registered device reports pending records.
 */
class FieldDeviceService
{
    public const HEADER = 'X-Field-Device';

    public const REVIEW_PERMISSION = 'Review:FieldSyncRecovery';

    /**
     * @param  array{name: string, platform?: ?string, app_version?: ?string, pending: array<int, array{date: string, count: int, oldest_captured_at?: ?string}>}  $data
     */
    public function report(User $user, string $deviceId, array $data): FieldDevice
    {
        return DB::transaction(function () use ($user, $deviceId, $data): FieldDevice {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = FieldDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->lockForUpdate()->first();
            $pending = $this->normalizePending($data['pending']);
            $attributes = ['name' => trim($data['name']), 'platform' => $data['platform'] ?? null, 'app_version' => $data['app_version'] ?? null,
                'pending_dates' => $pending, 'pending_count' => array_sum(array_column($pending, 'count')), 'reported_at' => now()];
            if (! $device) {
                return FieldDevice::query()->create(['user_id' => $user->id, 'device_id' => $deviceId, 'status' => 'active', ...$attributes]);
            }
            if (! $device->isActive()) {
                $attributes['history'] = [...($device->history ?? []), ['event' => 'reactivated', 'previous_status' => $device->status, 'at' => now()->toIso8601String(),
                    'closed_at' => $device->closed_at?->toIso8601String(), 'closed_by' => $device->closed_by, 'close_reason' => $device->close_reason]];
                $attributes += ['status' => 'active', 'closed_at' => null, 'closed_by' => null, 'close_reason' => null];
            }
            $device->update($attributes);

            return $device;
        });
    }

    /**
     * Device-initiated handshake: the client proves its queue is empty before it stops blocking.
     *
     * @param  array{pending: array<int, array<string, mixed>>}  $data
     */
    public function close(User $user, string $deviceId, array $data): FieldDevice
    {
        return DB::transaction(function () use ($user, $deviceId, $data): FieldDevice {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = FieldDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->lockForUpdate()->first();
            abort_unless($device, 404, 'This device is not registered.');
            if ($this->normalizePending($data['pending'] ?? []) !== []) {
                throw ValidationException::withMessages(['pending' => 'Synchronize all saved work before closing this device.']);
            }
            $device->update(['status' => 'closed', 'pending_dates' => [], 'pending_count' => 0, 'reported_at' => now(),
                'closed_at' => now(), 'closed_by' => $user->id, 'close_reason' => null,
                'history' => [...($device->history ?? []), ['event' => 'closed', 'at' => now()->toIso8601String(), 'by' => $user->id]]]);

            return $device;
        });
    }

    /**
     * Retire a lost or replaced device. Owners may retire only devices without reported pending work;
     * a device that still reports unsynchronized records needs an authorized manager other than the owner.
     */
    public function retire(User $actor, FieldDevice $device, string $reason): FieldDevice
    {
        return DB::transaction(function () use ($actor, $device, $reason): FieldDevice {
            User::query()->whereKey($device->user_id)->lockForUpdate()->firstOrFail();
            $device = FieldDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($device->status === 'retired', 409, 'This device is already retired.');
            abort_unless($this->canRetire($actor, $device), 403, 'A device with unsynchronized work can only be retired by an authorized manager other than its owner.');
            $device->update(['status' => 'retired', 'closed_at' => now(), 'closed_by' => $actor->id, 'close_reason' => $reason,
                'history' => [...($device->history ?? []), ['event' => 'retired', 'at' => now()->toIso8601String(), 'by' => $actor->id, 'reason' => $reason,
                    'previous_status' => $device->status, 'pending_dates' => $device->pending_dates, 'reported_at' => $device->reported_at?->toIso8601String()]]]);

            return $device;
        });
    }

    public function canRetire(User $actor, FieldDevice $device): bool
    {
        if ($device->status === 'retired') {
            return false;
        }
        if ((int) $actor->id === (int) $device->user_id) {
            return $device->pending_count === 0;
        }

        return $actor->can(self::REVIEW_PERMISSION);
    }

    public function canView(User $actor, FieldDevice $device): bool
    {
        return (int) $actor->id === (int) $device->user_id || $actor->can(self::REVIEW_PERMISSION);
    }

    public function currentDeviceId(): ?string
    {
        $header = request()->header(self::HEADER);

        return is_string($header) && Str::isUuid($header) ? strtolower($header) : null;
    }

    /**
     * Active registrations, other than the requesting device, that still report work for the DCR date.
     *
     * @return Collection<int, FieldDevice>
     */
    public function blockingDevices(SalesDcr $dcr, ?string $exceptDeviceId = null): Collection
    {
        $date = $dcr->dcr_date?->toDateString();
        if ($date === null) {
            return new Collection;
        }

        return FieldDevice::query()->where('user_id', $dcr->user_id)->where('status', 'active')->where('pending_count', '>', 0)->get()
            ->filter(fn (FieldDevice $device) => $device->pendingCountForDate($date) > 0
                && ($exceptDeviceId === null || strtolower($device->device_id) !== strtolower($exceptDeviceId)))
            ->values();
    }

    public function assertNoOtherDevicePending(SalesDcr $dcr): void
    {
        $devices = $this->blockingDevices($dcr, $this->currentDeviceId());
        if ($devices->isEmpty()) {
            return;
        }
        $names = $devices->map(fn (FieldDevice $device) => $device->name.' ('.$device->pendingCountForDate($dcr->dcr_date->toDateString()).' pending, reported '.$device->reported_at->diffForHumans().')')->implode(', ');
        throw ValidationException::withMessages(['field_devices' => 'Another registered device still holds unsynchronized work for this date: '.$names.'. Synchronize that device, or have an authorized manager retire it, before submitting this DCR.']);
    }

    /**
     * @return array{blocked: bool, devices: array<int, array{id: int, device_id: string, name: string, status: string, reported_at: string, pending_for_date: int}>}
     */
    public function summary(SalesDcr $dcr): array
    {
        $date = $dcr->dcr_date?->toDateString();
        $current = $this->currentDeviceId();
        $devices = $date === null ? new Collection : FieldDevice::query()->where('user_id', $dcr->user_id)->where('status', 'active')
            ->get()->filter(fn (FieldDevice $device) => $current === null || strtolower($device->device_id) !== $current)->values();

        return ['blocked' => $this->blockingDevices($dcr, $current)->isNotEmpty(),
            'devices' => $devices->map(fn (FieldDevice $device) => ['id' => $device->id, 'device_id' => $device->device_id, 'name' => $device->name, 'status' => $device->status,
                'reported_at' => $device->reported_at->toIso8601String(), 'pending_for_date' => $device->pendingCountForDate($date)])->all()];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pending
     * @return array<int, array{date: string, count: int, oldest_captured_at: ?string}>
     */
    private function normalizePending(array $pending): array
    {
        $byDate = [];
        foreach ($pending as $entry) {
            $date = (string) ($entry['date'] ?? '');
            $count = (int) ($entry['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $oldest = $entry['oldest_captured_at'] ?? null;
            $previous = $byDate[$date] ?? null;
            $byDate[$date] = ['date' => $date, 'count' => $count + ($previous['count'] ?? 0),
                'oldest_captured_at' => $previous && $previous['oldest_captured_at'] && (! $oldest || $previous['oldest_captured_at'] < $oldest) ? $previous['oldest_captured_at'] : $oldest];
        }
        ksort($byDate);

        return array_values($byDate);
    }
}

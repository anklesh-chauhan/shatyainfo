<?php

namespace App\Services\DataImport;

use App\Models\City;
use App\Models\State;

class CityImport
{
    /** @var array<string, City> */
    private array $cities = [];

    public function resolve(string $name, int $pin, string $state = '', string $country = ''): City
    {
        $key = mb_strtolower($name);
        $city = $this->cities[$key] ?? null;
        if ($city === null) {
            $matches = City::withTrashed()->whereNormalizedName($name)->limit(2)->get();
            if ($matches->count() > 1) {
                throw new ImportRowException($name.' matches multiple city records. Resolve the duplicate names in Cities before importing.');
            }
            $city = $matches->first();
            if ($city?->trashed()) {
                throw new ImportRowException($name.' is deleted. Restore it in Cities before importing.');
            }
        }
        if ($city !== null && $state === '') {
            return $this->cities[$key] = $city;
        }

        $states = State::query()->whereHas('country');
        if ($state !== '') {
            $states->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($state)])
                ->whereHas('country', fn ($query) => $query->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($country)]));
        } else {
            $states->whereIn('id', City::query()->select('state_id')
                ->whereHas('areaTowns', fn ($query) => $query->where('pin_code', $pin)));
        }
        $matches = $states->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new ImportRowException($state !== ''
                ? 'The state and country for '.$name.' must match one existing state and country.'
                : 'Supply the state and country for '.$name.'. The PIN does not identify one state and country.');
        }

        $matchedState = $matches->first();
        if ($city !== null && ($city->state_id !== $matchedState->id || $city->country_id !== $matchedState->country_id)) {
            throw new ImportRowException('The state and country for '.$name.' conflict with another entry or the city master.');
        }

        return $this->cities[$key] = $city ?? new City(['name' => $name, 'state_id' => $matchedState->id, 'country_id' => $matchedState->country_id]);
    }

    public function persist(string $name, int $stateId, int $countryId): City
    {
        $key = mb_strtolower($name);
        $city = $this->cities[$key] ?? null;
        if (! $city?->exists) {
            $city = City::withTrashed()->firstOrCreate(['name' => $name], ['state_id' => $stateId, 'country_id' => $countryId]);
        }
        if ($city->trashed() || $city->state_id !== $stateId || $city->country_id !== $countryId) {
            throw new ImportRowException($name.' changed since preview. Check the city master and preview again.');
        }

        return $this->cities[$key] = $city;
    }
}

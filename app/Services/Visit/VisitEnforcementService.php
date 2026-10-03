<?php

namespace App\Services\Visit;

use App\Models\Visit;
use App\Models\VisitPreference;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class VisitEnforcementService
{
    protected VisitPreference $prefs;

    public function __construct()
    {
        $this->prefs = VisitPreference::current();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK IN
    |--------------------------------------------------------------------------
    */

    public function validateCheckIn(?float $latitude, ?float $longitude): void
    {
        if (! $this->prefs->enable_check_in) {
            throw ValidationException::withMessages([
                'check_in' => 'Check-in is disabled by management.',
            ]);
        }

        if ($this->prefs->require_gps && (! $latitude || ! $longitude)) {
            throw ValidationException::withMessages([
                'gps' => 'GPS location is required for check-in.',
            ]);
        }
    }

    public function validateAfterCheckIn(Visit $visit): void
    {
        if (! $this->prefs->require_check_in_image) {
            return;
        }

        if (! $visit->hasCheckInImage()) {
            throw ValidationException::withMessages([
                'media' => 'You must upload a check-in image before continuing.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK OUT
    |--------------------------------------------------------------------------
    */

    public function validateCheckOut(Visit $visit, ?float $latitude, ?float $longitude, ?CarbonInterface $recordedAt = null): void
    {
        if (! $this->prefs->enable_check_out) {
            throw ValidationException::withMessages([
                'check_out' => 'Check-out is disabled by management.',
            ]);
        }

        if ($this->prefs->enforce_check_in_before_check_out && ! $visit->start_time) {
            throw ValidationException::withMessages([
                'check_out' => 'You must check in before checking out.',
            ]);
        }

        if ($this->prefs->require_gps && (! $latitude || ! $longitude)) {
            throw ValidationException::withMessages([
                'gps' => 'GPS location is required for check-out.',
            ]);
        }

        if ($this->prefs->enforce_minimum_duration && $visit->start_time) {
            $minutes = $visit->start_time->diffInMinutes($recordedAt ?? now());

            if ($minutes < $this->prefs->minimum_duration_minutes) {
                throw ValidationException::withMessages([
                    'duration' => "Minimum visit duration is {$this->prefs->minimum_duration_minutes} minutes.",
                ]);
            }
        }

        $this->validateRequiredFields($visit, 'checkout');

    }

    protected function validateRequiredFields(Visit $visit, string $errorKey = 'checkout'): void
    {
        $rules = $this->prefs->field_rules ?? [];
        $missing = [];

        foreach ($rules as $field => $config) {
            if (
                data_get($config, 'required') === true &&
                blank($visit->{$field})
            ) {
                $missing[] = ucfirst(str_replace('_', ' ', $field));
            }
        }

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                $errorKey => 'Please complete the following: '.implode(', ', $missing),
            ]);
        }
    }

    public function validateAfterCheckOut(Visit $visit): void
    {
        if (! $this->prefs->require_check_out_image) {
            return;
        }

        if (! $visit->hasCheckOutImage()) {
            throw ValidationException::withMessages([
                'media' => 'You must upload a check-out image before completing this visit.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE VISIT
    |--------------------------------------------------------------------------
    */

    public function validateBeforeSave(Visit $visit): void
    {
        // Only enforce check-in if check-in enabled
        if ($this->prefs->enable_check_in && ! $visit->start_time) {
            throw ValidationException::withMessages([
                'visit' => 'You must check in before saving this visit.',
            ]);
        }

        $this->validateGeneralVisitImage($visit);

        // If visit is already checked out and trying to complete,
        // then enforce full validation
        if ($visit->visit_status === 'checked_out_pending') {

            $this->validateCheckInImage($visit);
            $this->validateCheckOutImage($visit);
            $this->validateGeneralVisitImage($visit);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MEDIA REQUIREMENTS
    |--------------------------------------------------------------------------
    */
    protected function validateCheckInImage(Visit $visit): void
    {
        if (! $this->prefs->require_check_in_image) {
            return;
        }

        if (
            $this->prefs->require_check_in_image &&
            $visit->start_time &&
            ! $visit->hasCheckInImage()
        ) {
            throw ValidationException::withMessages([
                'checkin_image_required' => 'CHECKIN_IMAGE_REQUIRED',
            ]);
        }

    }

    protected function validateCheckOutImage(Visit $visit): void
    {
        if (! $this->prefs->require_check_out_image) {
            return;
        }

        if (
            $this->prefs->require_check_out_image &&
            $visit->end_time &&
            ! $visit->hasCheckOutImage()
        ) {
            throw ValidationException::withMessages([
                'checkout_image_required' => 'CHECKOUT_IMAGE_REQUIRED',
            ]);
        }
    }

    protected function validateGeneralVisitImage(Visit $visit): void
    {
        if (! $this->prefs->require_general_visit_image) {
            return;
        }

        if (
            $this->prefs->require_general_visit_image &&
            ! $visit->hasGeneralVisitImage()
        ) {
            throw ValidationException::withMessages([
                'require_general_visit_image' => 'REQUIRE_GENERAL_VISIT_IMAGE',
            ]);
        }

    }

    public function needsCheckInImage(Visit $visit): bool
    {
        if (! $this->prefs->require_check_in_image) {
            return false;
        }

        return ! $visit->hasCheckInImage();
    }

    public function needsCheckOutImage(Visit $visit): bool
    {
        if (! $this->prefs->require_check_out_image) {
            return false;
        }

        return ! $visit->hasCheckOutImage();
    }

    public function needsGeneralVisitImage(Visit $visit): bool
    {
        if (! $this->prefs->require_general_visit_image) {
            return false;
        }

        return ! $visit->hasGeneralVisitImage();
    }
}

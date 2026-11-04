<?php

namespace App\Observers;

use App\Jobs\ProcessMediaUploadJob;
use App\Models\Media;
use App\Models\SalesDcrExpense;
use App\Models\Visit;

class MediaObserver
{
    /**
     * Handle the Media "created" event.
     */
    public function created(Media $media): void
    {
        if ($media->model instanceof SalesDcrExpense) {
            return;
        }

        if ($media->model instanceof Visit) {

            if ($media->model->visit_status === 'started') {
                $media->attachTagBySlug('check-in');
            }

            if ($media->model->visit_status === 'completed') {
                $media->attachTagBySlug('check-out');
            }
        }

        ProcessMediaUploadJob::dispatch($media->id);
    }

    /**
     * Handle the Media "updated" event.
     */
    public function updated(Media $media): void
    {
        //
    }

    /**
     * Handle the Media "deleted" event.
     */
    public function deleted(Media $media): void
    {
        //
    }

    /**
     * Handle the Media "restored" event.
     */
    public function restored(Media $media): void
    {
        //
    }

    /**
     * Handle the Media "force deleted" event.
     */
    public function forceDeleted(Media $media): void
    {
        //
    }
}

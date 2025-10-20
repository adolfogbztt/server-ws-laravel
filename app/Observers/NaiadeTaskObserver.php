<?php

namespace App\Observers;

use App\Jobs\ProcessNaiadeJob;
use App\Models\NaiadeTask;

class NaiadeTaskObserver
{
    /**
     * Handle the NaiadeTask "created" event.
     */
    public function created(NaiadeTask $naiadeTask): void
    {
        // Dispatch job to process the Naiade task
        ProcessNaiadeJob::dispatch($naiadeTask)
            ->onQueue('naiade');
    }

    /**
     * Handle the NaiadeTask "updated" event.
     */
    public function updated(NaiadeTask $naiadeTask): void
    {
        //
    }

    /**
     * Handle the NaiadeTask "deleted" event.
     */
    public function deleted(NaiadeTask $naiadeTask): void
    {
        //
    }

    /**
     * Handle the NaiadeTask "restored" event.
     */
    public function restored(NaiadeTask $naiadeTask): void
    {
        //
    }

    /**
     * Handle the NaiadeTask "force deleted" event.
     */
    public function forceDeleted(NaiadeTask $naiadeTask): void
    {
        //
    }
}

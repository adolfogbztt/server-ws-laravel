<?php

namespace App\Providers;

use App\Listeners\HandleMessageReceived;
use App\Models\NaiadeTask;
use App\Observers\NaiadeTaskObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Events\MessageReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            MessageReceived::class,
            HandleMessageReceived::class,
        );

        NaiadeTask::observe(NaiadeTaskObserver::class);
    }
}

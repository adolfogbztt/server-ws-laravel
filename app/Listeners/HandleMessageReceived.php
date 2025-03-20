<?php

namespace App\Listeners;

use App\Jobs\PythonServiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleMessageReceived
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        /**
         * @var object $message
         * 
         */

        $message = json_decode($event->message);
        
        if ($message->event === 'client-request') {
            # code...
            $data = $message->data;
            PythonServiceJob::dispatch($data->model, $data->version, $data->photo_url, $data->token);
            \Log::info('Mensaje recibido: ' . $data->model . ' ' . $data->photo_url . ' ' . $data->version);
        }
    }
}

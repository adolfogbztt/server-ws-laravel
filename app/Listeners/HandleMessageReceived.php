<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
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
     * 
     * @param object $event
     * 
     * @return void
     */
    public function handle(object $event): void
    {
        $message = json_decode($event->message);
        
        if ($message->event === 'client-request') {
            $data = $message->data;
            PythonServiceV2Job::dispatch($data->service, $data->photo_url, $data->token)
                ->onQueue('python');
            $statusQueue = PythonServiceQueueMonitor::getQueueStatus();
            MessageSent::dispatch($data->token, 'queue-status', $statusQueue);
            // \Log::info('Mensaje recibido: ' . $data->model . ' ' . $data->photo_url . ' ' . $data->version);
        }
    }
}

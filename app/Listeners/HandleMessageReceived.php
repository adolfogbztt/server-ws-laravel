<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

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
            $data->token = $event->token;

            // Validate token
            if (!$this->validateToken($data->token)) {
                MessageSent::dispatch($data->token, 'invalid-token', '');
                return;
            }

            PythonServiceV2Job::dispatch($data->service, $data->photo_url, $data->token)
                ->onQueue('python');
            $statusQueue = PythonServiceQueueMonitor::getQueueStatus();
            MessageSent::dispatch($data->token, 'queue-status', $statusQueue);
        }
    }

    /**
     * @param string $token
     * 
     * @return bool
     */
    private function validateToken(string $token): bool
    {
        $response = Http::withToken($token)
            ->get(env('SIGA_API_URL') . 'validate-token');

        return $response->successful();
    }
}

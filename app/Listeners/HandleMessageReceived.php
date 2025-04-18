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

            // Validate token
            if (!$this->validateToken($data->token)) {
                broadcast(new MessageSent(
                    $message->channel,
                    'invalid-token',
                    [
                        'success' => false,
                        'message' => 'Invalid token',
                        'data' => null,
                    ]
                ));
                return;
            }

            PythonServiceV2Job::dispatch(
                $data->service,
                $data->photo_url,
                @$data->bgColor ?? 'transparent',
                $message->channel
            )
                ->onQueue('python');
            $statusQueue = PythonServiceQueueMonitor::getQueueStatus();

            broadcast(new MessageSent(
                $message->channel,
                'queue-status',
                $statusQueue
            ));
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

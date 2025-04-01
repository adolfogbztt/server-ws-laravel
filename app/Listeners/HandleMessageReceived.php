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
                $jsonData = json_encode([
                    'success' => false,
                    'message' => 'Invalid token',
                    'data' => null,
                ]);

                $base64Data = base64_encode($jsonData);

                broadcast(new MessageSent(
                    $message->channel,
                    'invalid-token',
                    $base64Data
                ));
                return;
            }

            PythonServiceV2Job::dispatch($data->service, $data->photo_url, $message->channel)
                ->onQueue('python');
            $statusQueue = PythonServiceQueueMonitor::getQueueStatus();

            $jsonData = json_encode($statusQueue);
            $base64Data = base64_encode($jsonData);

            broadcast(new MessageSent(
                $message->channel,
                'queue-status',
                $base64Data
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

<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var string
     */
    private string $token;
    
    /**
     * @var string
     */
    private string $event;
    
    /**
     * @var array|string
     */
    private array|string $data;

    /**
     * @param string $token
     * @param string $event
     * @param array|string $data
     */
    public function __construct(string $token, string $event, array|string $data)
    {
        $this->token = $token;
        $this->event = $event;
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new Channel('responses.' . $this->token);
    }

    /**
     * @return string
     */
    public function broadcastAs(): string
    {
        return $this->event;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return [
            'data' => $this->data
        ];
    }
}

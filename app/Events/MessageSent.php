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
    private string $channel;
    
    /**
     * @var string
     */
    private string $event;
    
    /**
     * @var string
     */
    private string $data;

    /**
     * @param string $channel
     * @param string $event
     * @param string $data
     */
    public function __construct(string $channel, string $event, string $data = '')
    {
        $this->channel = $channel;
        $this->event = $event;
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new Channel($this->channel);
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
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'data' => $this->data,
        ];
    }
}

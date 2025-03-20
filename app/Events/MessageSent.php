<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // public $message;
    public $token;
    public $data;

    public function __construct($token, $data)
    {
        $this->token = $token;
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new Channel('responses.' . $this->token);
    }

    public function broadcastWith()
    {
        return [
            'event' => 'server-response',
            'data' => $this->data
        ];
    }
}

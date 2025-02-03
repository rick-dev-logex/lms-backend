<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Request;

class RequestUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('requests.' . $this->request->responsible_id);
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->request->id,
            'status' => $this->request->status,
            'message' => 'La solicitud ha sido actualizada.',
        ];
    }
}

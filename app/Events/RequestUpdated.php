<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use App\Models\Request;

class RequestUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function broadcastOn()
    {
        return ['requests'];
    }

    public function broadcastAs()
    {
        return 'request.updated';
    }
}

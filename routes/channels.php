<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('requests.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

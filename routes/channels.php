<?php

use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private room WebSocket channel authorization
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    return true; // Allow all authenticated room participants
});

// Private user WebSocket channel authorization (for MatchFound events)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

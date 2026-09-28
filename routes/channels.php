<?php

use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Game-event WebSocket channel authorization (type-aware)
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    $room = \App\Models\Room::find($roomId);
    if (!$room) {
        return true;
    }

    if ($room->type === \App\Enums\RoomType::PRIVATE || $room->type === 'private') {
        return $room->players()->where('user_id', $user->id)->exists();
    }

    // Public / legacy / tournament / quick-match: unchanged (all authenticated users allowed)
    return true;
});

// Private room lobby WebSocket channel authorization (members only, private rooms only)
Broadcast::channel('room-lobby.{roomId}', function ($user, $roomId) {
    $room = \App\Models\Room::find($roomId);
    if (!$room || ($room->type !== \App\Enums\RoomType::PRIVATE && $room->type !== 'private')) {
        return false;
    }

    return $room->players()->where('user_id', $user->id)->exists();
});

// Private user WebSocket channel authorization (for MatchFound events)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

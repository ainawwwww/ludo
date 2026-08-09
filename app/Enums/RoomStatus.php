<?php

namespace App\Enums;

enum RoomStatus: string
{
    case WAITING = 'waiting';
    case PLAYING = 'playing';
    case FINISHED = 'finished';
    case CANCELLED = 'cancelled';
}

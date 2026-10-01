<?php

namespace App\Enums;

enum RoomType: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';
    case VIP = 'vip';
    case TEAM = 'team';
}

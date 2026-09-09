<?php

namespace App\Enums;

enum StoreItemType: string
{
    case AVATAR = 'avatar';
    case DICE_SKIN = 'dice_skin';
    case TOKEN_SKIN = 'token_skin';
    case BOARD_THEME = 'board_theme';
    case AVATAR_FRAME = 'avatar_frame';
}

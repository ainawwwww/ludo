<?php

namespace App\Enums;

enum ChatMessageType: string
{
    case TEXT = 'text';
    case EMOJI = 'emoji';
    case QUICK_CHAT = 'quick_chat';
}

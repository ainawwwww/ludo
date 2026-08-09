<?php

namespace App\Enums;

enum TransactionType: string
{
    case WIN = 'win';
    case LOSS = 'loss';
    case PURCHASE = 'purchase';
    case TOPUP = 'topup';
}

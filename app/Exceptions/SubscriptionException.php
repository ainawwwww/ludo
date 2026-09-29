<?php

namespace App\Exceptions;

use Exception;

class SubscriptionException extends Exception
{
    public function __construct(
        string $message,
        protected string $errorCode,
        protected int $statusCode = 400
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function alreadySubscribed(string $message = 'You already have an active VIP subscription.'): self
    {
        return new self($message, 'ALREADY_SUBSCRIBED', 409);
    }

    public static function paymentFailed(string $message = 'Dummy payment declined.'): self
    {
        return new self($message, 'PAYMENT_FAILED', 402);
    }

    public static function noActiveSubscription(string $message = 'No active VIP subscription found.'): self
    {
        return new self($message, 'NO_ACTIVE_SUBSCRIPTION', 404);
    }

    public static function rewardAlreadyClaimed(string $message = 'Daily VIP reward already claimed today.'): self
    {
        return new self($message, 'REWARD_ALREADY_CLAIMED', 409);
    }
}

<?php

namespace App\Services\Payment;

use App\Enums\SubscriptionTier;
use App\Models\User;

interface PaymentGatewayInterface
{
    /**
     * Charge user for VIP subscription.
     *
     * @return array{success: bool, transaction_id: string, gateway: string, amount: float, failure_reason: string|null}
     */
    public function charge(User $user, SubscriptionTier $tier, bool $forceFailure = false): array;
}

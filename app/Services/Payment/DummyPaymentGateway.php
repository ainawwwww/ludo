<?php

namespace App\Services\Payment;

use App\Enums\SubscriptionTier;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * DUMMY PAYMENT GATEWAY IMPLEMENTATION
 *
 * NOTE: This is a placeholder implementation for testing and demo flows.
 * REPLACE WITH REAL APPLE STOREKIT / GOOGLE PLAY BILLING SERVER VERIFICATION BEFORE PRODUCTION LAUNCH.
 */
class DummyPaymentGateway implements PaymentGatewayInterface
{
    public function charge(User $user, SubscriptionTier $tier, bool $forceFailure = false): array
    {
        $transactionId = 'txn_dummy_' . Str::lower(Str::random(12));

        if ($forceFailure) {
            return [
                'success' => false,
                'transaction_id' => $transactionId,
                'gateway' => 'dummy',
                'amount' => $tier->price(),
                'failure_reason' => 'forced_dummy_failure',
            ];
        }

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'gateway' => 'dummy',
            'amount' => $tier->price(),
            'failure_reason' => null,
        ];
    }
}

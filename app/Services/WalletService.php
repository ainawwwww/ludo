<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    /**
     * Debit a user's wallet with balance check, row locking, and idempotency by reference_id.
     *
     * @throws InsufficientBalanceException
     * @throws InvalidArgumentException
     */
    public function debit(
        User|int $user,
        int $amount,
        string $referenceId,
        TransactionType $type = TransactionType::ENTRY_FEE,
        string $currencyType = 'coins'
    ): Transaction {
        if ($amount < 0) {
            throw new InvalidArgumentException('Debit amount must be non-negative');
        }

        $userId = $user instanceof User ? $user->id : (int) $user;

        return DB::transaction(function () use ($userId, $amount, $referenceId, $type, $currencyType) {
            // Idempotency check per reference_id
            $existingTx = Transaction::where('user_id', $userId)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existingTx !== null) {
                return $existingTx;
            }

            // Lock wallet row for update
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id' => $userId,
                    'coins_balance' => 0,
                    'diamonds_balance' => 0,
                ]);
                // Re-lock
                $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            }

            $column = $currencyType === 'diamonds' ? 'diamonds_balance' : 'coins_balance';
            $currentBalance = (int) $wallet->{$column};

            if ($currentBalance < $amount) {
                throw new InsufficientBalanceException(
                    "Insufficient {$currencyType} balance. Required: {$amount}, available: {$currentBalance}"
                );
            }

            if ($amount > 0) {
                $wallet->{$column} = $currentBalance - $amount;
                $wallet->save();
            }

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'currency_type' => $currencyType,
                'amount' => -$amount,
                'reference_id' => $referenceId,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Credit a user's wallet with row locking and idempotency by reference_id.
     *
     * @throws InvalidArgumentException
     */
    public function credit(
        User|int $user,
        int $amount,
        string $referenceId,
        TransactionType $type = TransactionType::WIN,
        string $currencyType = 'coins'
    ): Transaction {
        if ($amount < 0) {
            throw new InvalidArgumentException('Credit amount must be non-negative');
        }

        $userId = $user instanceof User ? $user->id : (int) $user;

        return DB::transaction(function () use ($userId, $amount, $referenceId, $type, $currencyType) {
            // Idempotency check per reference_id
            $existingTx = Transaction::where('user_id', $userId)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existingTx !== null) {
                return $existingTx;
            }

            // Lock wallet row for update
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id' => $userId,
                    'coins_balance' => 0,
                    'diamonds_balance' => 0,
                ]);
                $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            }

            $column = $currencyType === 'diamonds' ? 'diamonds_balance' : 'coins_balance';

            if ($amount > 0) {
                $wallet->{$column} = ((int) $wallet->{$column}) + $amount;
                $wallet->save();
            }

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'currency_type' => $currencyType,
                'amount' => $amount,
                'reference_id' => $referenceId,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Get current balance for currency.
     */
    public function getBalance(User|int $user, string $currencyType = 'coins'): int
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $wallet = Wallet::where('user_id', $userId)->first();
        if (!$wallet) {
            return 0;
        }

        $column = $currencyType === 'diamonds' ? 'diamonds_balance' : 'coins_balance';
        return (int) $wallet->{$column};
    }
}

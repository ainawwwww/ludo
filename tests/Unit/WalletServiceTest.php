<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletService = new WalletService();
    }

    public function test_debit_decrements_balance_and_records_negative_transaction(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000, 'diamonds_balance' => 10]);

        $tx = $this->walletService->debit($user, 300, 'ref_debit_1', TransactionType::ENTRY_FEE, 'coins');

        $this->assertEquals(-300, $tx->amount);
        $this->assertEquals('ref_debit_1', $tx->reference_id);
        $this->assertEquals(700, $this->walletService->getBalance($user, 'coins'));
    }

    public function test_debit_throws_insufficient_balance_exception_when_balance_too_low(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 200, 'diamonds_balance' => 0]);

        $this->expectException(InsufficientBalanceException::class);
        $this->walletService->debit($user, 500, 'ref_debit_insufficient', TransactionType::ENTRY_FEE, 'coins');
    }

    public function test_debit_is_idempotent_per_reference_id(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000, 'diamonds_balance' => 0]);

        $tx1 = $this->walletService->debit($user, 400, 'ref_same_123', TransactionType::ENTRY_FEE, 'coins');
        $this->assertEquals(600, $this->walletService->getBalance($user, 'coins'));

        // Repeat debit with same reference_id
        $tx2 = $this->walletService->debit($user, 400, 'ref_same_123', TransactionType::ENTRY_FEE, 'coins');

        $this->assertEquals($tx1->id, $tx2->id);
        // Balance must remain 600, not double-debited to 200!
        $this->assertEquals(600, $this->walletService->getBalance($user, 'coins'));
        $this->assertEquals(1, Transaction::where('reference_id', 'ref_same_123')->count());
    }

    public function test_debit_rolls_back_balance_on_transaction_failure(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 1000, 'diamonds_balance' => 0]);

        try {
            DB::transaction(function () use ($user) {
                $this->walletService->debit($user, 500, 'ref_rollback_test', TransactionType::ENTRY_FEE, 'coins');
                throw new \RuntimeException('Simulated subsequent failure');
            });
        } catch (\RuntimeException $e) {
            // caught
        }

        // Balance should remain unchanged at 1000 due to DB transaction rollback
        $this->assertEquals(1000, $this->walletService->getBalance($user, 'coins'));
        $this->assertDatabaseMissing('transactions', ['reference_id' => 'ref_rollback_test']);
    }

    public function test_credit_increments_balance_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['coins_balance' => 500, 'diamonds_balance' => 0]);

        $tx1 = $this->walletService->credit($user, 1000, 'ref_win_1', TransactionType::WIN, 'coins');
        $this->assertEquals(1500, $this->walletService->getBalance($user, 'coins'));

        // Repeat credit with same reference_id
        $tx2 = $this->walletService->credit($user, 1000, 'ref_win_1', TransactionType::WIN, 'coins');
        $this->assertEquals($tx1->id, $tx2->id);
        // Balance remains 1500, not double credited
        $this->assertEquals(1500, $this->walletService->getBalance($user, 'coins'));
    }
}

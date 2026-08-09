<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\Transaction;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * GET /api/v1/wallet/balance
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "id": 1,
     *     "user_id": 1,
     *     "coins_balance": 1000,
     *     "diamonds_balance": 10
     *   }
     * }
     */
    public function getBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet ?? $user->wallet()->create(['coins_balance' => 1000, 'diamonds_balance' => 10]);

        return response()->json([
            'status' => 'success',
            'data' => new WalletResource($wallet),
        ]);
    }

    /**
     * GET /api/v1/wallet/transactions
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 1,
     *       "type": "topup",
     *       "currency_type": "coins",
     *       "amount": 500,
     *       "reference_id": "PAY_12345"
     *     }
     *   ]
     * }
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => TransactionResource::collection($transactions),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ]
        ]);
    }

    /**
     * POST /api/v1/wallet/topup
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "amount": 5000,
     *   "currency_type": "coins"
     * }
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "message": "Topup successful",
     *   "data": { ... }
     * }
     */
    public function topup(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
            'currency_type' => 'required|in:coins,diamonds',
        ]);

        $user = $request->user();
        $wallet = $user->wallet ?? $user->wallet()->create();

        if ($request->currency_type === 'coins') {
            $wallet->increment('coins_balance', $request->amount);
        } else {
            $wallet->increment('diamonds_balance', $request->amount);
        }

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'type' => TransactionType::TOPUP,
            'currency_type' => $request->currency_type,
            'amount' => $request->amount,
            'reference_id' => 'TOPUP_' . uniqid(),
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Topup successful',
            'data' => [
                'wallet' => new WalletResource($wallet->fresh()),
                'transaction' => new TransactionResource($transaction),
            ]
        ]);
    }
}

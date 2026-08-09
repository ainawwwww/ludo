<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseItemRequest;
use App\Http\Resources\StoreItemResource;
use App\Models\StoreItem;
use App\Models\Transaction;
use App\Models\UserInventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * GET /api/v1/store/items
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Golden Dice",
     *       "type": "dice_skin",
     *       "price": 500,
     *       "currency_type": "coins",
     *       "image_url": "https://example.com/dice/gold.png"
     *     }
     *   ]
     * }
     */
    public function index(): JsonResponse
    {
        $items = StoreItem::where('is_active', true)->get();

        return response()->json([
            'status' => 'success',
            'data' => StoreItemResource::collection($items),
        ]);
    }

    /**
     * POST /api/v1/store/purchase
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "item_id": 1
     * }
     */
    public function purchase(PurchaseItemRequest $request): JsonResponse
    {
        $user = $request->user();
        $item = StoreItem::findOrFail($request->item_id);

        if ($user->inventory()->where('item_id', $item->id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Item already owned'], 400);
        }

        $wallet = $user->wallet ?? $user->wallet()->create();

        if ($item->currency_type->value === 'coins') {
            if ($wallet->coins_balance < $item->price) {
                return response()->json(['status' => 'error', 'message' => 'Insufficient coins balance'], 400);
            }
            $wallet->decrement('coins_balance', $item->price);
        } else {
            if ($wallet->diamonds_balance < $item->price) {
                return response()->json(['status' => 'error', 'message' => 'Insufficient diamonds balance'], 400);
            }
            $wallet->decrement('diamonds_balance', $item->price);
        }

        UserInventory::create([
            'user_id' => $user->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'purchased_at' => now(),
        ]);

        Transaction::create([
            'user_id' => $user->id,
            'type' => TransactionType::PURCHASE,
            'currency_type' => $item->currency_type->value,
            'amount' => $item->price,
            'reference_id' => 'ITEM_' . $item->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item purchased successfully',
            'data' => new StoreItemResource($item),
        ]);
    }

    /**
     * GET /api/v1/store/inventory
     * Headers: Authorization: Bearer <token>
     */
    public function inventory(Request $request): JsonResponse
    {
        $items = $request->user()->inventory;

        return response()->json([
            'status' => 'success',
            'data' => StoreItemResource::collection($items),
        ]);
    }
}

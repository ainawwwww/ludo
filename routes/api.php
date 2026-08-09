<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // API Health & Status Endpoint
    Route::get('/', function () {
        return response()->json([
            'status' => 'online',
            'message' => 'Real-Time Ludo Backend API v1 is active and running',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Auth Public Endpoints
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/guest', [AuthController::class, 'guest']);

    // Protected API Endpoints (Sanctum Guard)
    Route::middleware('auth:sanctum')->group(function () {
        // Auth Profile & Logout
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Wallet Module
        Route::get('/wallet/balance', [WalletController::class, 'getBalance']);
        Route::get('/wallet/transactions', [WalletController::class, 'getTransactions']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);

        // Rooms & Matchmaking Module
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::post('/rooms/quick-match', [RoomController::class, 'quickMatch']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::post('/rooms/join', [RoomController::class, 'join']);

        // Real-time Game Engine Module
        Route::post('/game/start', [GameController::class, 'start']);
        Route::get('/game/state', [GameController::class, 'getGameState']);
        Route::post('/game/roll', [GameController::class, 'rollDice']);
        Route::post('/game/move', [GameController::class, 'moveToken']);

        // Leaderboard Ranking Module
        Route::get('/leaderboard', [LeaderboardController::class, 'index']);

        // Store & Customization Module
        Route::get('/store/items', [StoreController::class, 'index']);
        Route::post('/store/purchase', [StoreController::class, 'purchase']);
        Route::get('/store/inventory', [StoreController::class, 'inventory']);

        // Friends Social Module
        Route::get('/friends', [FriendController::class, 'index']);
        Route::post('/friends/request', [FriendController::class, 'sendRequest']);
        Route::post('/friends/{id}/respond', [FriendController::class, 'respondRequest']);

        // Room Live Chat Module
        Route::post('/chat/message', [ChatController::class, 'sendMessage']);
        Route::get('/chat/messages', [ChatController::class, 'getMessages']);
    });
});

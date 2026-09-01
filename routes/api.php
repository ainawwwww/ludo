<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DirectMessageController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\LobbyController;
use App\Http\Controllers\Api\MatchmakingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuickMatchChatController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\TournamentController;
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
    Route::post('/auth/google', [AuthController::class, 'google']);

    // Countries Public Endpoint (needed on registration screen)
    Route::get('/countries', [CountryController::class, 'index']);

    // Protected API Endpoints (Sanctum Guard)
    Route::middleware('auth:sanctum')->group(function () {
        // Auth Profile & Logout
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Home Screen
        Route::get('/home', [HomeController::class, 'index']);

        // Profile Module
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);

        // Wallet Module
        Route::get('/wallet/balance', [WalletController::class, 'getBalance']);
        Route::get('/wallet/transactions', [WalletController::class, 'getTransactions']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);

        // Quick Match Module
        Route::post('/quick-match', [RoomController::class, 'quickMatch']);
        Route::post('/quick-match/join', [MatchmakingController::class, 'join']);
        Route::post('/quick-match/leave', [MatchmakingController::class, 'leave']);
        Route::get('/quick-match/status', [MatchmakingController::class, 'status']);
        Route::get('/quick-match/active-match', [MatchmakingController::class, 'activeMatch']);
        Route::post('/quick-match/start', [GameController::class, 'start']);
        Route::get('/quick-match/state', [GameController::class, 'getGameState']);
        Route::post('/quick-match/roll', [GameController::class, 'rollDice']);
        Route::post('/quick-match/move', [GameController::class, 'moveToken']);
        Route::post('/quick-match/forfeit', [GameController::class, 'forfeitMatch']);
        Route::post('/quick-match/message', [QuickMatchChatController::class, 'sendMessage']);
        Route::get('/quick-match/messages', [QuickMatchChatController::class, 'getMessages']);

        // Queue-Based Matchmaking Module
        Route::post('/matchmaking/join', [MatchmakingController::class, 'join']);
        Route::post('/matchmaking/leave', [MatchmakingController::class, 'leave']);
        Route::get('/matchmaking/status', [MatchmakingController::class, 'status']);
        Route::get('/matchmaking/active-match', [MatchmakingController::class, 'activeMatch']);

        // Tournament Ladder System Module
        Route::get('/tournaments', [TournamentController::class, 'index']);
        Route::get('/tournaments/{id}', [TournamentController::class, 'show']);
        Route::post('/tournaments/{id}/join', [TournamentController::class, 'join']);
        Route::post('/tournaments/{id}/continue', [TournamentController::class, 'continueMatch']);
        Route::post('/tournaments/{id}/leave', [TournamentController::class, 'leave']);
        Route::get('/tournaments/{id}/progress', [TournamentController::class, 'progress']);

        // Real-time Game Engine Module
        Route::post('/game/start', [GameController::class, 'start']);
        Route::get('/game/state', [GameController::class, 'getGameState']);
        Route::post('/game/roll', [GameController::class, 'rollDice']);
        Route::post('/game/move', [GameController::class, 'moveToken']);
        Route::post('/game/forfeit', [GameController::class, 'forfeitMatch']);

        // Leaderboard Ranking Module
        Route::get('/leaderboard', [LeaderboardController::class, 'index']);

        // Dedicated League Flow Module
        Route::get('/leagues', [LeagueController::class, 'index']);
        Route::get('/leagues/my-division', [LeagueController::class, 'myDivision']);

        // Store & Customization Module
        Route::get('/store/items', [StoreController::class, 'index']);
        Route::post('/store/purchase', [StoreController::class, 'purchase']);
        Route::get('/store/inventory', [StoreController::class, 'inventory']);

        // Friends Social Module
        Route::get('/friends', [FriendController::class, 'index']);
        Route::get('/friends/requests', [FriendController::class, 'incomingRequests']);
        Route::post('/friends/request', [FriendController::class, 'sendRequest']);
        Route::post('/friends/{id}/respond', [FriendController::class, 'respondRequest']);

        // Follow Module
        Route::post('/users/{id}/follow', [\App\Http\Controllers\Api\FollowController::class, 'follow']);
        Route::post('/users/{id}/unfollow', [\App\Http\Controllers\Api\FollowController::class, 'unfollow']);
        Route::get('/users/{id}/follow-status', [\App\Http\Controllers\Api\FollowController::class, 'status']);

        // Direct Messaging & Voice Notes Module
        Route::get('/friends/conversations', [DirectMessageController::class, 'getConversations']);
        Route::post('/friends/{friend_id}/message', [DirectMessageController::class, 'sendMessage']);
        Route::get('/friends/{friend_id}/messages', [DirectMessageController::class, 'getMessages']);
        Route::delete('/friends/messages/{message_id}', [DirectMessageController::class, 'deleteMessage']);

        // Room Live Chat Module
        Route::post('/chat/message', [ChatController::class, 'sendMessage']);
        Route::get('/chat/messages', [ChatController::class, 'getMessages']);

        // Lobby Data Module (Phase 1)
        Route::get('/lobby/explore', [LobbyController::class, 'explore']);
        Route::get('/lobby/hot', [LobbyController::class, 'hot']);
        Route::get('/lobby/my', [LobbyController::class, 'my']);

        // Rooms Module
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::post('/rooms/join', [RoomController::class, 'join']);
        Route::post('/rooms/{room}/join', [RoomController::class, 'joinAsListener']);
        Route::post('/rooms/{room}/seat', [RoomController::class, 'takeSeat']);
        Route::post('/rooms/{room}/take-seat', [RoomController::class, 'takeSeat']);
        Route::post('/rooms/{room}/leave-seat', [RoomController::class, 'leaveSeat']);
        Route::post('/rooms/quick-match', [RoomController::class, 'quickMatch']);
    });
});


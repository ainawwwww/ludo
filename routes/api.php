<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DirectMessageController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\LobbyController;
use App\Http\Controllers\Api\MatchmakingController;
use App\Http\Controllers\Api\PrivateRoomController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\VipRoomController;
use App\Http\Controllers\Api\TeamRoomController;
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

    // Public Tournament Winners Endpoint
    Route::get('/tournaments/winners', [TournamentController::class, 'winners']);

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
        Route::match(['put', 'post'], '/profile', [ProfileController::class, 'update']);

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
        Route::post('/quick-match/timeout', [GameController::class, 'processTimeout']);
        Route::post('/quick-match/message', [QuickMatchChatController::class, 'sendMessage']);
        Route::get('/quick-match/messages', [QuickMatchChatController::class, 'getMessages']);

        // Queue-Based Matchmaking Module
        Route::post('/matchmaking/join', [MatchmakingController::class, 'join']);
        Route::post('/matchmaking/leave', [MatchmakingController::class, 'leave']);
        Route::get('/matchmaking/status', [MatchmakingController::class, 'status']);
        Route::get('/matchmaking/active-match', [MatchmakingController::class, 'activeMatch']);

        // Tournament Ladder System Module
        Route::get('/tournaments', [TournamentController::class, 'index']);
        Route::get('/tournaments/winners', [TournamentController::class, 'winners']);
        Route::get('/tournaments/my-history', [TournamentController::class, 'myHistory']);
        Route::get('/tournaments/{id}', [TournamentController::class, 'show']);
        Route::post('/tournaments/{id}/join', [TournamentController::class, 'join']);
        Route::post('/tournaments/{id}/continue', [TournamentController::class, 'continueMatch']);
        Route::post('/tournaments/{id}/leave', [TournamentController::class, 'leave']);
        Route::post('/tournaments/{id}/claim', [TournamentController::class, 'claim']);
        Route::get('/tournaments/{id}/progress', [TournamentController::class, 'progress']);

        // Real-time Game Engine Module
        Route::post('/game/start', [GameController::class, 'start']);
        Route::get('/game/state', [GameController::class, 'getGameState']);
        Route::post('/game/roll', [GameController::class, 'rollDice']);
        Route::post('/game/move', [GameController::class, 'moveToken']);
        Route::post('/game/forfeit', [GameController::class, 'forfeitMatch']);
        Route::post('/game/timeout', [GameController::class, 'processTimeout']);

        // Leaderboard Ranking Module
        Route::get('/leaderboard', [LeaderboardController::class, 'index']);

        // Dedicated League Flow Module
        Route::get('/leagues', [LeagueController::class, 'index']);
        Route::get('/leagues/my-division', [LeagueController::class, 'myDivision']);

        // Store & Customization Module
        Route::get('/store/items', [StoreController::class, 'index']);
        Route::post('/store/purchase', [StoreController::class, 'purchase']);
        Route::get('/store/inventory', [StoreController::class, 'inventory']);
        Route::post('/store/equip', [StoreController::class, 'equip']);

        // Friends Social Module
        Route::get('/friends', [FriendController::class, 'index']);
        Route::get('/friends/search', [FriendController::class, 'search']);
        Route::get('/users/search', [FriendController::class, 'search']);
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

        // Events & Rewards Module
        Route::get('/events/daily-tasks', [EventController::class, 'getDailyTasks']);
        Route::post('/events/daily-tasks/{id}/claim', [EventController::class, 'claimDailyTask']);
        Route::get('/events/arrival-chest', [EventController::class, 'getArrivalChest']);
        Route::post('/events/arrival-chest/claim', [EventController::class, 'claimArrivalChest']);
        // Voice Module (Agora RTC Token)
        Route::post('/voice/token', [\App\Http\Controllers\Api\VoiceController::class, 'generateToken']);

        // Rooms Module
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::post('/rooms/join', [RoomController::class, 'join']);
        Route::post('/rooms/{room}/join', [RoomController::class, 'joinAsListener']);
        Route::post('/rooms/{room}/seat', [RoomController::class, 'takeSeat']);
        Route::post('/rooms/{room}/take-seat', [RoomController::class, 'takeSeat']);
        Route::post('/rooms/{room}/leave-seat', [RoomController::class, 'leaveSeat']);
        Route::post('/rooms/{room}/leave', [RoomController::class, 'leave']);
        Route::post('/rooms/quick-match', [RoomController::class, 'quickMatch']);

        // Private Room Module (Phase 3)
        Route::prefix('private-rooms')->group(function () {
            Route::post('/', [PrivateRoomController::class, 'create']);
            Route::post('/join', [PrivateRoomController::class, 'join'])->middleware('throttle:10,1');
            Route::get('/current', [PrivateRoomController::class, 'current']);
            Route::get('/active', [PrivateRoomController::class, 'current']);
            Route::get('/{room}', [PrivateRoomController::class, 'show']);
            Route::post('/{room}/ready', [PrivateRoomController::class, 'ready']);
            Route::post('/{room}/leave', [PrivateRoomController::class, 'leave']);
            Route::post('/{room}/start', [PrivateRoomController::class, 'start']);
        });

        // VIP Room Module (Milestone 1)
        Route::prefix('vip-rooms')->group(function () {
            Route::post('/', [VipRoomController::class, 'create']);
            Route::post('/join', [VipRoomController::class, 'join'])->middleware('throttle:10,1');
            Route::get('/current', [VipRoomController::class, 'current']);
            Route::get('/active', [VipRoomController::class, 'current']);
            Route::get('/{room}', [VipRoomController::class, 'show']);
            Route::post('/{room}/ready', [VipRoomController::class, 'ready']);
            Route::post('/{room}/leave', [VipRoomController::class, 'leave']);
            Route::post('/{room}/start', [VipRoomController::class, 'start']);
        });

        // Team Room Module (2v2 Party Lobby & Matchmaking)
        Route::prefix('team-rooms')->group(function () {
            Route::post('/', [TeamRoomController::class, 'create']);
            Route::post('/create', [TeamRoomController::class, 'create']);
            Route::post('/join', [TeamRoomController::class, 'join'])->middleware('throttle:10,1');
            Route::post('/join-solo', [TeamRoomController::class, 'joinSolo']);
            Route::get('/current', [TeamRoomController::class, 'current']);
            Route::get('/active', [TeamRoomController::class, 'current']);
            Route::get('/{code}', [TeamRoomController::class, 'show']);
            Route::post('/leave', [TeamRoomController::class, 'leave']);
            Route::post('/ready', [TeamRoomController::class, 'ready']);
            Route::post('/ready-for-match', [TeamRoomController::class, 'readyForMatch']);
        });

        // VIP Subscription Module (Milestone 2)
        Route::prefix('subscription')->group(function () {
            Route::get('/plans', [SubscriptionController::class, 'plans']);
            Route::get('/current', [SubscriptionController::class, 'current']);
            Route::post('/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:5,1');
            Route::post('/cancel', [SubscriptionController::class, 'cancel']);
            Route::post('/claim-daily-reward', [SubscriptionController::class, 'claimDailyReward']);
        });
    });
});


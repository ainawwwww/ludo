<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     * 
     * Request Payload (JSON):
     * {
     *   "username": "ludo_king",
     *   "email": "user@example.com",
     *   "password": "secretpassword",
     *   "country": "PK",
     *   "avatar_url": "https://example.com/avatar1.png"
     * }
     * 
     * Success Response (201 Created):
     * {
     *   "status": "success",
     *   "message": "User registered successfully",
     *   "data": {
     *     "token": "1|sanctum_token_string_here",
     *     "user": {
     *       "id": 1,
     *       "username": "ludo_king",
     *       "email": "user@example.com",
     *       "coins": 1000,
     *       "diamonds": 10,
     *       "level": 1,
     *       "xp": 0
     *     }
     *   }
     * }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        // Auto-create wallet for user
        Wallet::create([
            'user_id' => $user->id,
            'coins_balance' => 1000,
            'diamonds_balance' => 10,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ]
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     * 
     * Request Payload (JSON):
     * {
     *   "username": "ludo_king",
     *   "password": "secretpassword"
     * }
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "message": "Logged in successfully",
     *   "data": {
     *     "token": "2|sanctum_token_string_here",
     *     "user": { ... }
     *   }
     * }
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in successfully',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ]
        ]);
    }

    /**
     * GET /api/v1/auth/me
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": {
     *     "id": 1,
     *     "username": "ludo_king",
     *     "coins": 1000,
     *     "diamonds": 10
     *   }
     * }
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     * Headers: Authorization: Bearer <token>
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "message": "Logged out successfully"
     * }
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * POST /api/v1/auth/guest
     * 
     * Request Payload (JSON, optional):
     * {
     *   "device_id": "DEVICE_ABC_123"
     * }
     * 
     * Success Response - Existing Device Resumed (200 OK):
     * {
     *   "status": "success",
     *   "message": "Guest session resumed",
     *   "data": {
     *     "token": "4|sanctum_token_string_here",
     *     "user": {
     *       "id": 5,
     *       "device_id": "DEVICE_ABC_123",
     *       "username": "Guest4829",
     *       "is_guest": true,
     *       "coins": 500,
     *       "diamonds": 10
     *     }
     *   }
     * }
     * 
     * Success Response - New Guest Created (201 Created):
     * {
     *   "status": "success",
     *   "message": "Guest account created successfully",
     *   "data": { ... }
     * }
     */
    public function guest(\App\Http\Requests\GuestLoginRequest $request): JsonResponse
    {
        $deviceId = $request->input('device_id');

        // Case 1: Device ID provided and guest user exists -> Resume existing session
        if (!empty($deviceId)) {
            $existingUser = User::where('device_id', $deviceId)
                ->where('is_guest', true)
                ->first();

            if ($existingUser) {
                // Revoke old tokens if any
                $existingUser->tokens()->delete();
                $token = $existingUser->createToken('guest_token')->plainTextToken;

                return response()->json([
                    'status' => 'success',
                    'message' => 'Guest session resumed',
                    'data' => [
                        'token' => $token,
                        'user' => new UserResource($existingUser),
                    ]
                ], 200);
            }
        }

        // Case 2 & 3: New device or omitted device_id -> Create new guest user
        do {
            $username = 'Guest' . random_int(1000, 9999);
        } while (User::where('username', $username)->exists());

        $user = User::create([
            'device_id' => $deviceId ?: null,
            'username' => $username,
            'email' => null,
            'password' => null,
            'is_guest' => true,
            'is_active' => true,
            'level' => 1,
            'xp' => 0,
        ]);

        // Auto-create wallet for guest with 500 starting coins
        Wallet::create([
            'user_id' => $user->id,
            'coins_balance' => 500,
            'diamonds_balance' => 10,
        ]);

        $token = $user->createToken('guest_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Guest account created successfully',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ]
        ], 201);
    }
}

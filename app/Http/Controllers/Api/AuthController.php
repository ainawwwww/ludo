<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Auth\GoogleTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     * 
     * Request Payload (JSON):
     * {
     *   "username": "ludo_king",
     *   "email": "user@example.com",
     *   "phone": "3001234567",
     *   "password": "secretpassword",
     *   "country": "PK",
     *   "country_code": "+92",            // Optional: Auto-inferred from country ISO code if omitted
     *   "avatar_url": "https://example.com/avatar1.png",
     *   "device_id": "DEV_ANDROID_9988",  // Optional: Device identifier
     *   "metadata": {                     // Optional: Custom dynamic client metadata
     *     "app_version": "1.2.0",
     *     "platform": "android"
     *   }
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
     *       "country": "PK",
     *       "country_code": "+92",
     *       "device_id": "DEV_ANDROID_9988",
     *       "metadata": { "app_version": "1.2.0", "platform": "android" },
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
    public static function generateNextGuestUsername(): string
    {
        $guestUsernames = User::where('username', 'LIKE', 'Guest%')->pluck('username');

        $maxNumber = -1;
        foreach ($guestUsernames as $name) {
            $suffix = substr($name, 5);
            if (ctype_digit($suffix)) {
                $num = (int) $suffix;
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
            }
        }

        $nextNumber = $maxNumber + 1;
        $candidate = 'Guest' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);

        while (User::where('username', $candidate)->exists()) {
            $nextNumber++;
            $candidate = 'Guest' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
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
     *       "username": "Guest0000",
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

        // Case 1: Device ID provided and user exists with this device_id -> Resume existing session
        if (!empty($deviceId)) {
            $existingUser = User::where('device_id', $deviceId)->first();

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

        // Case 2 & 3: New device or omitted device_id -> Create next sequential guest user (Guest0000, Guest0001, etc.)
        $username = self::generateNextGuestUsername();

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

    /**
     * POST /api/v1/auth/google
     * 
     * Request Payload (JSON):
     * {
     *   "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6..."
     * }
     * 
     * Success Response - Existing User Logged In / Account Linked (200 OK):
     * {
     *   "status": "success",
     *   "message": "Logged in successfully",
     *   "data": {
     *     "token": "5|sanctum_token_string_here",
     *     "user": {
     *       "id": 1,
     *       "username": "john_doe",
     *       "email": "john@example.com",
     *       "google_id": "109876543210987654321",
     *       "auth_provider": "google",
     *       "avatar_url": "https://lh3.googleusercontent.com/a/...",
     *       "coins": 1000,
     *       "diamonds": 10,
     *       "is_guest": false
     *     }
     *   }
     * }
     * 
     * Success Response - New User Created (201 Created):
     * {
     *   "status": "success",
     *   "message": "User registered and logged in with Google",
     *   "data": {
     *     "token": "6|sanctum_token_string_here",
     *     "user": { ... }
     *   }
     * }
     * 
     * Error Response - Invalid or Expired Token (401 Unauthorized):
     * {
     *   "status": "error",
     *   "message": "Invalid Google token"
     * }
     * 
     * Error Response - Email Linked to Another Account (409 Conflict):
     * {
     *   "status": "error",
     *   "message": "Email is already linked to another account"
     * }
     */
    public function google(GoogleLoginRequest $request, GoogleTokenVerifier $verifier): JsonResponse
    {
        $idToken = $request->validated('id_token');

        $payload = $verifier->verify($idToken);

        if (!$payload || empty($payload['sub'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Google token',
            ], 401);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;
        $avatarUrl = $payload['picture'] ?? null;

        // 1. Check if user already exists with this google_id
        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Logged in successfully',
                'data' => [
                    'token' => $token,
                    'user' => new UserResource($user),
                ]
            ], 200);
        }

        // 2. Check if user exists with this email (e.g. created via guest or email auth)
        if (!empty($email)) {
            $existingUser = User::where('email', $email)->first();

            if ($existingUser) {
                // If existing email is already linked to a DIFFERENT google_id -> 409 Conflict
                if (!empty($existingUser->google_id) && $existingUser->google_id !== $googleId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Email is already linked to another account',
                    ], 409);
                }

                // Link account to Google
                $existingUser->google_id = $googleId;
                $existingUser->auth_provider = 'google';
                $existingUser->is_guest = false;
                if (empty($existingUser->avatar_url) && !empty($avatarUrl)) {
                    $existingUser->avatar_url = $avatarUrl;
                }
                $existingUser->save();

                $token = $existingUser->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'status' => 'success',
                    'message' => 'Logged in successfully',
                    'data' => [
                        'token' => $token,
                        'user' => new UserResource($existingUser),
                    ]
                ], 200);
            }
        }

        // 3. Create new user if no match found
        $baseUsername = Str::slug($name ?: ($email ? explode('@', $email)[0] : 'user'), '_');
        if (empty($baseUsername)) {
            $baseUsername = 'user';
        }

        $username = $baseUsername;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . random_int(100, 9999);
        }

        $user = User::create([
            'username' => $username,
            'email' => $email,
            'google_id' => $googleId,
            'auth_provider' => 'google',
            'avatar_url' => $avatarUrl,
            'is_guest' => false,
            'is_active' => true,
            'level' => 1,
            'xp' => 0,
        ]);

        // Auto-create wallet for user with 500 starting coins
        Wallet::create([
            'user_id' => $user->id,
            'coins_balance' => 500,
            'diamonds_balance' => 10,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'User registered and logged in with Google',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ]
        ], 201);
    }
}

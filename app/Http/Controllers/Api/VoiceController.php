<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Libraries\Agora\RtcTokenBuilder2;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoiceController extends Controller
{
    /**
     * POST /api/v1/voice/token
     * Generates dynamic RTC Voice Token for Agora Voice Channels.
     * Body: { "channel_name": "room_5", "uid": 17 }
     */
    public function generateToken(Request $request): JsonResponse
    {
        $request->validate([
            'channel_name' => 'required|string',
            'uid' => 'nullable|integer',
        ]);

        $appId = config('services.agora.app_id', env('AGORA_APP_ID', ''));
        $appCertificate = config('services.agora.app_certificate', env('AGORA_APP_CERTIFICATE', ''));
        $channelName = $request->channel_name;
        $uid = $request->uid ?? $request->user()?->id ?? rand(1000, 99999);
        $tokenExpire = 3600;       // Token valid for 1 hour
        $privilegeExpire = 3600;   // Privileges valid for 1 hour

        Log::info("[AGORA VOICE] Token request: channel={$channelName}, uid={$uid}, appId=" . substr($appId, 0, 8) . '...');

        // If no App ID, voice is not configured
        if (empty($appId)) {
            Log::warning('[AGORA VOICE] No AGORA_APP_ID configured in .env');
            return response()->json([
                'status' => 'error',
                'message' => 'Agora voice not configured. Set AGORA_APP_ID in .env',
                'data' => [
                    'app_id' => '',
                    'token' => '',
                    'channel_name' => $channelName,
                    'uid' => $uid,
                    'expires_at' => time() + $tokenExpire,
                ]
            ], 200);
        }

        // If App Certificate is set, generate secure AccessToken2
        $token = '';
        if (!empty($appCertificate)) {
            try {
                $token = RtcTokenBuilder2::buildTokenWithUid(
                    $appId,
                    $appCertificate,
                    $channelName,
                    (int) $uid,
                    RtcTokenBuilder2::ROLE_PUBLISHER,
                    $tokenExpire,
                    $privilegeExpire
                );
                Log::info("[AGORA VOICE] Token generated successfully (length=" . strlen($token) . ")");
            } catch (\Throwable $e) {
                Log::error("[AGORA VOICE] Token generation failed: " . $e->getMessage());
                return response()->json([
                    'status' => 'error',
                    'message' => 'Token generation failed',
                ], 500);
            }
        } else {
            // App ID Testing Mode (no certificate): pass empty token
            // Agora allows this when "App ID" auth mode is enabled in Console
            Log::info("[AGORA VOICE] No App Certificate set — using App ID testing mode (no token)");
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Voice token generated successfully',
            'data' => [
                'app_id' => $appId,
                'token' => $token,
                'channel_name' => $channelName,
                'uid' => $uid,
                'expires_at' => time() + $tokenExpire,
            ]
        ]);
    }
}


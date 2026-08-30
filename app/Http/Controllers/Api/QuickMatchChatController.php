<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatMessageType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QuickMatchChatController extends Controller
{
    /**
     * POST /api/v1/quick-match/message
     * Headers: Authorization: Bearer <token>
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quick_match_id' => 'nullable|integer|exists:rooms,id',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'message' => 'required|string|max:500',
            'message_type' => 'nullable|string|in:text,emoji,voice,quick_chat',
        ]);

        $roomId = (int) ($validated['quick_match_id'] ?? $validated['room_id'] ?? 0);
        if ($roomId <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'The quick_match_id field is required.',
            ], 422);
        }

        $user = $request->user();

        $message = ChatMessage::create([
            'room_id' => $roomId,
            'user_id' => $user->id,
            'message' => $validated['message'],
            'message_type' => $validated['message_type'] ?? ChatMessageType::TEXT->value,
            'created_at' => now(),
        ]);

        $message->setRelation('user', $user);

        // Invalidate message cache for this room
        try {
            Cache::forget("room:messages:{$roomId}");
        } catch (\Throwable $e) {
            // Safe fallback
        }

        // Broadcast to all players in the room channel
        broadcast(new \App\Events\ChatMessageSent($roomId, $message));

        return response()->json([
            'status' => 'success',
            'data' => new ChatMessageResource($message),
        ]);
    }

    /**
     * GET /api/v1/quick-match/messages?quick_match_id=1
     * Headers: Authorization: Bearer <token>
     */
    public function getMessages(Request $request): JsonResponse
    {
        $roomId = (int) ($request->query('quick_match_id') ?? $request->query('room_id') ?? 0);
        if ($roomId <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Valid quick_match_id is required.',
            ], 422);
        }

        $cacheKey = "room:messages:{$roomId}";
        
        try {
            $messages = Cache::remember($cacheKey, 2, function () use ($roomId) {
                return ChatMessage::with(['user' => fn($q) => $q->select('id', 'username')])
                    ->where('room_id', $roomId)
                    ->orderBy('id', 'asc')
                    ->limit(50)
                    ->get();
            });
        } catch (\Throwable $e) {
            $messages = ChatMessage::with(['user' => fn($q) => $q->select('id', 'username')])
                ->where('room_id', $roomId)
                ->orderBy('id', 'asc')
                ->limit(50)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => ChatMessageResource::collection($messages),
        ]);
    }
}

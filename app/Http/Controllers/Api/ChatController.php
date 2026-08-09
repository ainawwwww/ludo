<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatMessageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\Room;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * POST /api/v1/chat/message
     * Headers: Authorization: Bearer <token>
     * 
     * Request Payload (JSON):
     * {
     *   "room_id": 1,
     *   "message": "Good luck everyone!",
     *   "message_type": "text"
     * }
     */
    public function sendMessage(SendChatMessageRequest $request): JsonResponse
    {
        $user = $request->user();

        $message = ChatMessage::create([
            'room_id' => $request->room_id,
            'user_id' => $user->id,
            'message' => $request->message,
            'message_type' => $request->input('message_type', ChatMessageType::TEXT->value),
            'created_at' => now(),
        ]);

        $message->load('user');

        return response()->json([
            'status' => 'success',
            'data' => new ChatMessageResource($message),
        ]);
    }

    /**
     * GET /api/v1/chat/messages?room_id=1
     * Headers: Authorization: Bearer <token>
     */
    public function getMessages(Request $request): JsonResponse
    {
        $request->validate(['room_id' => 'required|integer|exists:rooms,id']);

        $messages = ChatMessage::with('user')
            ->where('room_id', $request->room_id)
            ->orderBy('id', 'asc')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => ChatMessageResource::collection($messages),
        ]);
    }
}

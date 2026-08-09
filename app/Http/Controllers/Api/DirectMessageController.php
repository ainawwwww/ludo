<?php

namespace App\Http\Controllers\Api;

use App\Enums\FriendStatus;
use App\Events\DirectMessageDeleted;
use App\Events\DirectMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectMessageRequest;
use App\Http\Resources\DirectMessageResource;
use App\Models\DirectMessage;
use App\Models\Friend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * DirectMessageController
 *
 * Handles 1-on-1 direct messaging between accepted friends, supporting text
 * messages, WhatsApp-style voice notes, conversation lists, and soft deletion.
 *
 * ---
 * 1. POST /api/v1/friends/{friend_id}/message
 * Headers: Authorization: Bearer <token>
 * Content-Type: multipart/form-data (or application/json for text)
 *
 * Request Payload — Text Message:
 * {
 *   "type": "text",
 *   "message": "Hey, ready for a Ludo game?"
 * }
 *
 * Request Payload — Voice Note (multipart/form-data):
 *   type: "voice"
 *   voice_note: [Binary File: audio/mp3, audio/m4a, etc., max 5MB]
 *   voice_duration: 15 (integer, seconds)
 *
 * Success Response (201 Created):
 * {
 *   "status": "success",
 *   "message": "Message sent successfully",
 *   "data": {
 *     "id": 101,
 *     "sender_id": 1,
 *     "receiver_id": 2,
 *     "type": "voice",
 *     "message": null,
 *     "voice_url": "/storage/voice_messages/1/a1b2c3d4.mp3",
 *     "voice_duration": 15,
 *     "is_read": false,
 *     "created_at": "2026-08-09T18:00:00Z"
 *   }
 * }
 *
 * ---
 * 2. GET /api/v1/friends/{friend_id}/messages
 * Headers: Authorization: Bearer <token>
 *
 * Returns message history between authenticated user and the specified friend.
 *
 * ---
 * 3. GET /api/v1/friends/conversations
 * Headers: Authorization: Bearer <token>
 *
 * Returns all active conversations with the last message preview (shows "🎤 Voice message" for voice notes).
 *
 * ---
 * 4. DELETE /api/v1/friends/messages/{message_id}
 * Headers: Authorization: Bearer <token>
 *
 * Soft-deletes a message sent by the authenticated user and broadcasts DirectMessageDeleted.
 */
class DirectMessageController extends Controller
{
    /**
     * POST /api/v1/friends/{friend_id}/message
     */
    public function sendMessage(DirectMessageRequest $request, int $friendId): JsonResponse
    {
        $userId = $request->user()->id;

        // Verify accepted friendship
        if (!$this->isAcceptedFriend($userId, $friendId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only send messages to accepted friends',
            ], 403);
        }

        $type = $request->input('type');
        $voiceUrl = null;
        $voiceDuration = null;
        $messageText = null;

        if ($type === 'voice') {
            $file = $request->file('voice_note');
            // Store file with UUID auto-generated name on public disk
            $path = $file->store("voice_messages/{$userId}", 'public');
            $voiceUrl = '/storage/' . $path;
            $voiceDuration = (int) $request->input('voice_duration');
        } else {
            $messageText = $request->input('message');
        }

        $message = DirectMessage::create([
            'sender_id' => $userId,
            'receiver_id' => $friendId,
            'type' => $type,
            'message' => $messageText,
            'voice_url' => $voiceUrl,
            'voice_duration' => $voiceDuration,
            'is_read' => false,
            'created_at' => now(),
        ]);

        // Broadcast real-time WebSocket event to receiver's channel
        broadcast(new DirectMessageSent($message));

        return response()->json([
            'status' => 'success',
            'message' => 'Message sent successfully',
            'data' => new DirectMessageResource($message),
        ], 201);
    }

    /**
     * GET /api/v1/friends/{friend_id}/messages
     */
    public function getMessages(Request $request, int $friendId): JsonResponse
    {
        $userId = $request->user()->id;

        if (!$this->isAcceptedFriend($userId, $friendId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only view messages with accepted friends',
            ], 403);
        }

        // Mark all unread incoming messages from this friend as read
        DirectMessage::where('sender_id', $friendId)
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        // SoftDeletes automatically filters out deleted_at !== null
        $messages = DirectMessage::where(function ($q) use ($userId, $friendId) {
            $q->where('sender_id', $userId)->where('receiver_id', $friendId);
        })->orWhere(function ($q) use ($userId, $friendId) {
            $q->where('sender_id', $friendId)->where('receiver_id', $userId);
        })->orderBy('created_at', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => DirectMessageResource::collection($messages),
        ]);
    }

    /**
     * GET /api/v1/friends/conversations
     */
    public function getConversations(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Get all accepted friend IDs
        $friendships = Friend::where(function ($q) use ($userId) {
            $q->where('user_id', $userId)->orWhere('friend_id', $userId);
        })->where('status', FriendStatus::ACCEPTED->value)->get();

        $conversations = [];

        foreach ($friendships as $friendship) {
            $otherUserId = $friendship->user_id === $userId ? $friendship->friend_id : $friendship->user_id;
            $otherUser = User::find($otherUserId);

            if (!$otherUser) {
                continue;
            }

            // Get last non-deleted message
            $lastMessage = DirectMessage::where(function ($q) use ($userId, $otherUserId) {
                $q->where('sender_id', $userId)->where('receiver_id', $otherUserId);
            })->orWhere(function ($q) use ($userId, $otherUserId) {
                $q->where('sender_id', $otherUserId)->where('receiver_id', $userId);
            })->latest('created_at')->first();

            $preview = null;
            if ($lastMessage) {
                $preview = $lastMessage->type === 'voice' ? '🎤 Voice message' : $lastMessage->message;
            }

            $unreadCount = DirectMessage::where('sender_id', $otherUserId)
                ->where('receiver_id', $userId)
                ->where('is_read', false)
                ->count();

            $conversations[] = [
                'friend' => [
                    'id' => $otherUser->id,
                    'username' => $otherUser->username,
                    'avatar_url' => $otherUser->avatar_url,
                ],
                'last_message' => $preview,
                'last_message_type' => $lastMessage?->type,
                'last_message_at' => $lastMessage?->created_at?->toIso8601String(),
                'unread_count' => $unreadCount,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $conversations,
        ]);
    }

    /**
     * DELETE /api/v1/friends/messages/{message_id}
     */
    public function deleteMessage(Request $request, int $messageId): JsonResponse
    {
        $userId = $request->user()->id;
        $message = DirectMessage::findOrFail($messageId);

        // Only original sender can delete
        if ($message->sender_id !== $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only delete your own messages',
            ], 403);
        }

        // Soft delete (sets deleted_at; audio file is retained on storage for potential restore/audit)
        $message->delete();

        // Broadcast deletion event to receiver's private channel
        broadcast(new DirectMessageDeleted($message->id, $message->sender_id, $message->receiver_id));

        return response()->json([
            'status' => 'success',
            'message' => 'Message deleted successfully',
        ]);
    }

    /**
     * Helper to verify bidirectional accepted friendship between two users.
     */
    private function isAcceptedFriend(int $userId, int $friendId): bool
    {
        return Friend::where(function ($q) use ($userId, $friendId) {
            $q->where('user_id', $userId)->where('friend_id', $friendId);
        })->orWhere(function ($q) use ($userId, $friendId) {
            $q->where('user_id', $friendId)->where('friend_id', $userId);
        })->where('status', FriendStatus::ACCEPTED->value)->exists();
    }
}

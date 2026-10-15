<?php

namespace App\Http\Controllers\Api;

use App\Events\MusicChangedEvent;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MusicController extends Controller
{
    /**
     * GET /api/v1/music/search?q=
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $tag = trim((string) $request->query('tag', ''));
        $cacheKey = 'jamendo_search_' . md5($q . '_' . $tag);

        try {
            $tracks = Cache::remember($cacheKey, 600, function () use ($q, $tag) {
                $clientId = config('services.jamendo.client_id');
                if (!$clientId) {
                    return [];
                }

                $params = [
                    'client_id' => $clientId,
                    'format' => 'json',
                    'limit' => 30,
                    'audioformat' => 'mp32',
                ];

                if (!empty($q)) {
                    $params['namesearch'] = $q;
                } elseif (!empty($tag)) {
                    $params['tags'] = $tag;
                } else {
                    $params['order'] = 'popularity_week';
                }

                $response = Http::timeout(10)->get('https://api.jamendo.com/v3.0/tracks/', $params);

                if (!$response->successful()) {
                    return [];
                }

                $data = $response->json();
                $rawTracks = $data['results'] ?? [];

                $filtered = [];
                foreach ($rawTracks as $item) {
                    $audioUrl = (string) ($item['audio'] ?? '');
                    if (empty($audioUrl)) {
                        continue;
                    }

                    $filtered[] = [
                        'id' => (string) ($item['id'] ?? ''),
                        'name' => (string) ($item['name'] ?? 'Untitled Track'),
                        'artist' => (string) ($item['artist_name'] ?? 'Unknown Artist'),
                        'image' => (string) ($item['image'] ?? ''),
                        'audio' => $audioUrl,
                    ];
                }

                return $filtered;
            });

            return response()->json([
                'success' => true,
                'data' => $tracks ?? [],
            ]);
        } catch (\Throwable $e) {
            // Gracefully handle errors: never return a 500 stack trace
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }
    }

    /**
     * POST /api/v1/rooms/{room}/music
     */
    public function syncRoomMusic(Request $request, $roomId): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:play,pause,stop',
            'track' => 'nullable|array',
            'track.id' => 'nullable|string',
            'track.name' => 'nullable|string',
            'track.artist' => 'nullable|string',
            'track.image' => 'nullable|string',
            'track.audio' => 'nullable|string',
        ]);

        $user = $request->user();
        $room = Room::find($roomId);

        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Room not found'], 404);
        }

        // Verify user belongs to room following room chat authorization
        if ($room->isPrivateOrVip()) {
            $isMember = $room->created_by === $user->id || $room->players()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized access to room music'], 403);
            }
        }

        $action = $request->input('action');
        $track = $request->input('track');

        // Broadcast to other users on the room channel
        try {
            broadcast(new MusicChangedEvent((int) $room->id, $action, $track, (int) $user->id))->toOthers();
        } catch (\Throwable $e) {
            broadcast(new MusicChangedEvent((int) $room->id, $action, $track, (int) $user->id));
        }

        return response()->json([
            'success' => true,
            'action' => $action,
            'track' => $track,
        ]);
    }
}

# Private Room Realtime Architecture & Protocol

## 1. WebSocket Channels & Authorization

Laravel Echo / Pusher channels use a prefix convention where `PrivateChannel('name')` on the server maps to `private-name` on the wire.

| Logical Channel (Laravel) | Wire Channel (Pusher / Reverb / Echo) | Channel Class | Authorization Guard | Description |
| :--- | :--- | :--- | :--- | :--- |
| `room-lobby.{roomId}` | `private-room-lobby.{roomId}` | `PrivateChannel` | `room_players.user_id = auth.id` AND `room.type = private` | Dedicated lobby channel for private room state changes prior to and during match transition. Only members (or members of cancelled rooms) can subscribe. |
| `room.{roomId}` | `private-room.{roomId}` | `PrivateChannel` | **Type-aware**: If `type=private`, requires `room_players.user_id = auth.id`. If `type=public` (quick match, public room, tournament), any authenticated user is allowed. | Game-events channel where in-game actions (`dice_rolled`, `token_moved`, `turn_changed`, `game_ended`) are broadcast. |
| `user.{id}` | `private-user.{id}` | `PrivateChannel` | `auth.id = id` | Personal user channel used for direct notifications such as matchmaking (`match_found`). |
| `App.Models.User.{id}` | `private-App.Models.User.{id}` | `PrivateChannel` | `auth.id = id` | Default Laravel notification channel. |

### Channel Authorization Rules (`routes/channels.php`)
1. **Lobby Channel (`room-lobby.{roomId}`)**:
   - Rejects non-members with 403.
   - Rejects non-private rooms with 403.
   - Room members retain authorization even after cancellation so they can receive the final `cancelled` or `expired` event.
2. **Match Game Channel (`room.{roomId}`)**:
   - For `type = private`: Only active players (`room_players`) can subscribe. Non-participants are denied.
   - For `type = public` / legacy / tournament: Open to any authenticated user (unchanged legacy behavior).

---

## 2. Event Specification: `PrivateRoomUpdated`

- **Laravel Event Class**: `App\Events\PrivateRoomUpdated`
- **Broadcast Name (`broadcastAs`)**: `private_room.updated`
- **Channel (`broadcastOn`)**: `room-lobby.{roomId}` (Wire: `private-room-lobby.{roomId}`)
- **Commit Guard**: `public bool $afterCommit = true;` (Dispatched ONLY after database transactions successfully commit).

### Payload Schema (`broadcastWith`)
```json
{
  "reason": "joined | left | ready | started | cancelled | expired",
  "actor_user_id": 123,
  "snapshot": {
    "id": 45,
    "code": "849201",
    "status": "waiting | playing | cancelled | finished",
    "max_players": 4,
    "entry_fee": 100,
    "turn_seconds": 15,
    "host_user_id": 123,
    "players": [
      {
        "user_id": 123,
        "name": "HostPlayer",
        "avatar": "https://...",
        "seat": 1,
        "color": "red",
        "is_ready": true,
        "is_host": true
      },
      {
        "user_id": 124,
        "name": "GuestPlayer",
        "avatar": "https://...",
        "seat": 2,
        "color": "green",
        "is_ready": false,
        "is_host": false
      }
    ],
    "can_start": false,
    "game_id": null,
    "version": 4
  },
  "version": 4
}
```

### Snapshot Guarantees
- **User-Agnostic**: The `snapshot` inside the realtime event contains NO client-specific fields (`my_seat`, `is_host` are omitted). Clients derive their own seat and host status locally by comparing their authenticated user ID with `players[].user_id` and `host_user_id`.
- **No Sensitive Leakage**: Emails, phone numbers, auth tokens, and internal timestamps are strictly excluded.
- **Single Source of Truth**: The snapshot format is defined by `PrivateRoomService::publicSnapshot()` and shared with `PrivateRoomResource` to guarantee 0% shape drift.

---

## 3. Client State Synchronization & Resync Rules

Clients must track their local `current_version` (initialized from the REST API response when joining or creating a room).

### Version Handling:
1. **Normal Transition (`event.version == current_version + 1`)**:
   - Apply the snapshot immediately.
   - Update `current_version = event.version`.
2. **Old / Duplicate Event (`event.version <= current_version`)**:
   - Discard the event silently. Out-of-order or duplicate deliveries must never overwrite newer state.
3. **Missed Event Gap (`event.version > current_version + 1`)**:
   - A gap indicates one or more intermediate events were missed (e.g. temporary network drop).
   - Trigger a resync request: `GET /api/v1/private-rooms/current`.
   - Overwrite local state with the returned API snapshot, which contains the authoritative latest version.
4. **App Resume / Reconnection**:
   - Whenever the WebSocket reconnects or the app returns from background/sleep, immediately call `GET /api/v1/private-rooms/current` to resync state.

---

## 4. Known v1 Limitations

1. **No Host Heartbeat / WebRTC Presence**:
   - In v1, there is no real-time ping/heartbeat or presence tracking for the host in the waiting lobby.
   - If a host abruptly kills the app without clicking "Leave Room", the room remains in `waiting` state until expired by the scheduled `private-rooms:expire` command (TTL default: 30 minutes).
2. **In-game Disconnection / Stuck Match Cleanup**:
   - If a private match starts and all players close their apps without forfeiting, the room is automatically cleaned up by `private-rooms:expire-stuck` (scheduled every 5 minutes, cleanup after `playing_stuck_hours` default 3 hours, or immediately when the game completes).

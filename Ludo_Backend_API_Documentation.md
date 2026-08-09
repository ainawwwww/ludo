# Ludo Backend — Complete API Documentation (for Flutter App Development)

**Backend:** Laravel 11 | **Auth:** Laravel Sanctum | **Real-time:** Laravel Reverb (WebSocket) | **Live game state:** Redis | **DB:** MySQL
**Base URL (local dev):** `http://127.0.0.1:8000/api/v1`
**Project location:** `G:\GAMES\ludo_backend`

---

## 0. General Rules for Every Request

- All endpoints except `auth/register`, `auth/login`, `auth/guest`, `auth/google` require:
  ```
  Authorization: Bearer {token}
  Accept: application/json
  ```
- All request/response bodies are JSON **except** endpoints that upload files (avatar upload, voice note upload), which must be sent as `multipart/form-data`.
- Standard validation error shape (HTTP 422):
  ```json
  {
    "message": "The email field is required.",
    "errors": {
      "email": ["The email field is required."]
    }
  }
  ```
- Standard success shape (most endpoints):
  ```json
  {
    "status": "success",
    "data": { ... }
  }
  ```

---

## 1. Authentication

### 1.1 Register
```
POST /auth/register
```
Body:
```json
{
  "username": "wania123",
  "email": "wania@test.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```
Response `200`:
```json
{
  "user": { "id": 1, "username": "wania123", "email": "wania@test.com", "coins": 500, "diamonds": 0, "level": 1 },
  "token": "1|xxxxxxxx"
}
```
Notes: Country code is auto-inferred if omitted; can also be passed explicitly. Supports optional `device_id` field.

### 1.2 Login
```
POST /auth/login
```
Body: `{ "email": "wania@test.com", "password": "password123" }`
Response: same shape as register.
Errors: `401` wrong credentials, `422` missing fields.

### 1.3 Guest Login (with device persistence)
```
POST /auth/guest
```
Body (all optional):
```json
{ "device_id": "unique-device-identifier" }
```
Response `200`:
```json
{
  "user": { "id": 45, "username": "Guest7492", "is_guest": true, "coins": 500, "diamonds": 0, "level": 1 },
  "token": "1|abcdxyz..."
}
```
**Behavior:**
- If `device_id` is provided and already exists for a guest user → returns the SAME existing user with a new token (no duplicate account).
- If `device_id` is provided but new → creates a new guest user, saves the device_id.
- If `device_id` is omitted → always creates a brand-new guest account (no persistence).

Flutter should generate/store a persistent `device_id` (e.g. via `device_info_plus`) and always send it.

### 1.4 Google Sign-In
```
POST /auth/google
```
Body: `{ "id_token": "<google_id_token_from_flutter_google_sign_in_package>" }`
Response `200`: same shape as register/login (`user`, `token`).
Errors:
- `401` — invalid/expired Google token
- `409` — email already linked to a different google_id

Notes: Flutter uses the `google_sign_in` package to get the ID token from the device, then sends it here. Backend verifies it server-side via Google's official verification — email/name/picture are never trusted from the client, only from the verified token payload.

### 1.5 Get Current User
```
GET /auth/me
```
Response: current user's profile object.

### 1.6 Logout
```
POST /auth/logout
```
Invalidates the current Sanctum token.

---

## 2. Home Screen

### 2.1 Get Home Data
```
GET /home
```
Response `200`:
```json
{
  "status": "success",
  "data": {
    "username": "Wania",
    "level": 12,
    "coins": 4500,
    "diamonds": 30,
    "current_league": { "name": "Silver", "icon_url": "/images/leagues/silver.png" },
    "global_rank": 154,
    "avatar_url": "/storage/avatars/abc.jpg"
  }
}
```
Use this to populate the home screen: username, level, coins, gems, league, rank, avatar — all in one call.

---

## 3. Profile

### 3.1 View Profile
```
GET /profile
```
Response `200`:
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "Wania",
    "level": 12,
    "avatar_url": "/storage/avatars/abc.jpg",
    "country": "Pakistan",
    "gender": "female",
    "dob": "1998-05-12",
    "bio": "Ludo lover 🎲",
    "total_games_played": 40,
    "total_wins": 25,
    "total_losses": 15,
    "win_rate": 62.5,
    "league_info": {
      "current_tier": "Silver",
      "points": 1200,
      "progress_status": "mid",
      "next_tier": "Gold"
    },
    "achievements": {
      "level_badge": { "name": "Silver", "icon": "/images/badges/level_silver.png", "level": 12 },
      "favorite_dice": "Golden Dragon Dice"
    }
  }
}
```
`progress_status` = `low` / `mid` / `high` — shows where the user stands within their current league tier's point range.
`favorite_dice` will be `null` if the user has no equipped dice skin.

### 3.2 Edit Profile
```
PUT /profile
```
**Must be `multipart/form-data`** (because of avatar upload).
Fields (all optional, send only what's changing):
```
name: string, max 50 — limited to 3 changes per rolling 24 hours
avatar: image file, jpg/png, max 2MB
gender: male | female | unspecified
dob: date (YYYY-MM-DD), must be 13+ years old
country: string, max 100
bio: string, max 255
```
Response `200`: updated `ProfileResource` (same shape as 3.1).
Errors:
- `422` — 4th name change same day → `"Name can only be changed 3 times per day"`
- `422` — underage dob → `"You must be at least 13 years old."`
- `422` — invalid image type or oversized (>2MB)
- `422` — duplicate username

---

## 4. Wallet

### 4.1 Get Balance
```
GET /wallet/balance
```
Response: `{ "coins": 4500, "diamonds": 30 }`

### 4.2 Get Transactions
```
GET /wallet/transactions
```
Response: paginated list of `{ type, currency_type, amount, reference_id, created_at }`.
`type` is one of: `win`, `loss`, `purchase`, `topup`, `gift`, `entry_fee`.

### 4.3 Topup
```
POST /wallet/topup
```
Body: `{ "amount": 1000, "currency_type": "coins", "payment_method": "test" }`
⚠️ **Not production-ready** — this currently adds coins directly without verifying real payment. Must be wired to Google Play Billing / App Store receipt verification before launch.

---

## 5. Rooms (manual room creation/joining — separate from Quick Match)

### 5.1 Create Room
```
POST /rooms
```
Body: `{ "type": "public", "max_players": 4, "entry_fee": 0 }`
Response: room object including `room_id`, `room_code`.

### 5.2 List Rooms
```
GET /rooms
```

### 5.3 Room Detail
```
GET /rooms/{id}
```

### 5.4 Join Room (by code)
```
POST /rooms/join
```
Body: `{ "room_code": "ABC123" }`
Seat and color are auto-assigned server-side — client does NOT choose these.

---

## 6. Matchmaking (Quick Match — Queue-Based, No Room Selection by User)

**Important:** The client never picks or sends a room_id up front. The flow is asynchronous:
1. Call `/matchmaking/join` → immediate response is either `matched` (rare, only if someone was already waiting) or `waiting`.
2. If `waiting`, **listen on the WebSocket private channel `private-user.{your_user_id}`** for a `match.found` event — this fires the moment enough players are found.
3. Alternatively, poll `/matchmaking/status`.

### 6.1 Join Matchmaking Queue
```
POST /matchmaking/join
```
Body: `{ "max_players": 2, "entry_fee": 0 }`  (`max_players` is `2` or `4`)
Response — waiting:
```json
{ "status": "success", "data": { "status": "waiting", "queue_position": 1 } }
```
Response — matched immediately:
```json
{
  "status": "success",
  "data": {
    "status": "matched",
    "room_id": 12,
    "game_id": 9,
    "players": [
      { "user_id": 1, "username": "Wania", "avatar_url": "...", "seat_position": 1, "color": "red" },
      { "user_id": 2, "username": "Ali", "avatar_url": "...", "seat_position": 2, "color": "green" }
    ]
  }
}
```
Errors: `400` insufficient coins for entry_fee.

**How matching actually works (important for Flutter integration):**
- Backend maintains a Redis-backed queue per `(max_players, entry_fee)` combination.
- When enough players (2 or 4) are waiting in the same queue, the backend atomically pulls them out (Redis lock — safe even if thousands of users hit "Quick Match" at the same second), deducts entry_fee from each wallet, creates the Room + Game + Redis game state, and pushes a `MatchFound` WebSocket event privately to each matched player's own channel.
- If a matched player's coin balance turned out to be insufficient at the exact moment of matching (race condition), that match is cancelled for everyone and valid players are automatically re-queued.

### 6.2 Leave Queue
```
POST /matchmaking/leave
```
Removes the user from whatever queue they're currently in.

### 6.3 Matchmaking Status (polling fallback)
```
GET /matchmaking/status
```
Response (queued): `{ "status": "queued", "queue_position": 2, "queue_size": 3, "max_players": 4, "entry_fee": 0 }`
Response (idle): `{ "status": "idle" }`

### WebSocket Event: `match.found`
Channel: `private-user.{user_id}`
Payload:
```json
{ "room_id": 12, "game_id": 9, "players": [...], "timestamp": "..." }
```
Flutter should subscribe to this private channel right after login/quick-match-join, and navigate to the game screen the moment this event fires.

---

## 7. Game Engine

⚠️ **`dice_value` must NOT be sent by the client on `/game/move`.** The server tracks the last rolled value in Redis per room and uses that — client only sends which token to move. This prevents cheating.

### 7.1 Start Game
```
POST /game/start
```
Body: `{ "room_id": 1 }`
Broadcasts `GameStarted` event to the room.

### 7.2 Get Game State
```
GET /game/state?room_id=1
```
Response: current turn, dice value (if rolled), all token positions, per-player colors/seats.

### 7.3 Roll Dice
```
POST /game/roll
```
Body: `{ "room_id": 1 }`
Only the current-turn player may call this (`403` otherwise). Broadcasts `DiceRolled`.
Errors: `403` not your turn, `400` game not started / already rolled this turn.

### 7.4 Move Token
```
POST /game/move
```
Body:
```json
{ "room_id": 1, "token_index": 1 }
```
**Do NOT include `dice_value`** — server uses the last roll from Redis automatically.
Broadcasts `TokenMoved` and `TurnChanged` (and `GameEnded` if this move wins the game).
Errors: `400` no dice rolled yet / illegal move, `403` not your token / not your turn.

### 7.5 Turn Timer (automatic, no client call needed)
If the current-turn player doesn't roll/move within ~20 seconds, the backend automatically skips/auto-plays their turn via a queued job and broadcasts `TurnChanged` to move to the next player. Flutter should show a visible countdown timer using the `TurnChanged` event's timestamp, but does not need to call anything to trigger the skip — it happens server-side.

### WebSocket Events (Reverb) — Game Channel
Broadcast on a room/game channel (confirm exact channel name with backend — typically `room.{room_id}` or `game.{game_id}`):
- `GameStarted`
- `DiceRolled` — `{ user_id, dice_value }`
- `TokenMoved` — `{ user_id, token_id, new_position, killed_tokens: [] }`
- `TurnChanged` — `{ next_user_id }`
- `GameEnded` — `{ winner_id, rewards }`
- `RoomUpdated`
- `PlayerDisconnected`

---

## 8. Store

### 8.1 List Items
```
GET /store/items
```
Response: list of `{ id, name, type, price, currency_type, image_url }`. `type` is `avatar`, `dice_skin`, or `board_theme`.

### 8.2 Purchase Item
```
POST /store/purchase
```
Body: `{ "item_id": 1 }`
Errors: `400` insufficient balance, `404` item not found, `422`/`400` already owned.

### 8.3 Inventory
```
GET /store/inventory
```
Response: list of owned items with `is_equipped` flag.

---

## 9. Friends

### 9.1 List Friends
```
GET /friends
```

### 9.2 Send Friend Request
```
POST /friends/request
```
Body: `{ "friend_id": 2 }`
Errors: `422` cannot add self, `422` duplicate request, `404` user not found.

### 9.3 Respond to Friend Request
```
POST /friends/{id}/respond
```
Body: `{ "status": "accepted" }` (or `"rejected"`)
Errors: `403` responding to someone else's request, `404` not found, `422` invalid status.

---

## 10. Room Chat (group text chat inside a game room — separate from friend DMs)

### 10.1 Send Room Message
```
POST /chat/message
```
Body: `{ "room_id": 1, "message": "Hello!" }`
Errors: `403` not a member of the room, `422` empty/oversized message.

### 10.2 Get Room Messages
```
GET /chat/messages?room_id=1
```
Stored permanently in the `chat_messages` MySQL table.

---

## 11. Friend Direct Messages (1-on-1 chat: text + WhatsApp-style voice notes)

Only works between users who are **accepted friends** (see section 9). All messages are stored in the `direct_messages` MySQL table; voice note audio files are stored on server disk (`storage/app/public/voice_messages/{user_id}/`) — the DB only stores the file's URL and duration, not the audio itself.

### 11.1 Send Text Message
```
POST /friends/{friend_id}/message
```
Body (JSON is fine for text):
```json
{ "type": "text", "message": "Hello!" }
```

### 11.2 Send Voice Note
```
POST /friends/{friend_id}/message
```
**Must be `multipart/form-data`:**
```
type: voice
voice_note: <audio file — mp3/m4a/aac/wav/ogg, max 5MB>
voice_duration: 8   (seconds, max 300)
```
Response `201`:
```json
{
  "status": "success",
  "data": {
    "id": 55,
    "sender_id": 1,
    "receiver_id": 2,
    "type": "voice",
    "message": null,
    "voice_url": "/storage/voice_messages/1/uuid.mp3",
    "voice_duration": 8,
    "is_read": false,
    "created_at": "..."
  }
}
```
Errors: `403` not friends, `422` invalid/oversized/wrong-MIME audio file, `422` missing voice_duration.
Real Flutter flow: record with `record` or `flutter_sound` package → get local file → upload here → play back via the returned `voice_url` when the recipient taps the bubble.

### 11.3 Get Conversation History
```
GET /friends/{friend_id}/messages
```
Returns all messages (text + voice) between the two users, oldest → newest, excluding soft-deleted messages.
⚠️ **Known gap:** this should mark incoming messages as read but currently does not update `is_read` — see Known Issues section below.

### 11.4 Get Conversations List (chat inbox)
```
GET /friends/conversations
```
Response: list of `{ friend: {id, username, avatar_url}, last_message, last_message_type, last_message_at, unread_count }`.
For voice-note last messages, `last_message` shows `"🎤 Voice message"`.

### 11.5 Delete a Message
```
DELETE /friends/messages/{message_id}
```
Only the sender can delete. Soft-deletes (message disappears from both users' views immediately), broadcasts `DirectMessageDeleted`. Voice audio file is NOT deleted from disk immediately — a daily cleanup job permanently removes both the DB row and the file after 7 days.
Errors: `403` not the sender, `404` message not found.

### WebSocket Events — Direct Messages
Channel: `private-user.{receiver_id}`
- `direct.message.sent` — full message payload (see 11.2 response shape)
- `direct.message.deleted` — `{ message_id, sender_id, receiver_id }`

---

## 12. Leaderboard

```
GET /leaderboard?type=global
GET /leaderboard?type=country
GET /leaderboard?type=friends
```
Response: paginated ranked list of `{ rank, user_id, username, avatar_url, total_wins, total_games, win_rate }`.
`type=country` filters by the requesting user's country. `type=friends` only includes accepted friends. Global rank (`users.rank`) is recalculated by a scheduled job every 10 minutes — see Known Issues if it appears stale.

---

## 13. WebSocket / Reverb Connection Setup (Flutter)

- Use `laravel_echo_flutter` or a raw `web_socket_channel` connecting to the Reverb server.
- Private channels (`private-user.{id}`, and the game/room channel) require Sanctum-authenticated channel authorization — Flutter must pass the Bearer token when Echo requests `/broadcasting/auth`.
- Subscribe to `private-user.{my_user_id}` immediately after login for: `match.found`, `direct.message.sent`, `direct.message.deleted`.
- Subscribe to the room/game channel only while inside an active game screen, for: `GameStarted`, `DiceRolled`, `TokenMoved`, `TurnChanged`, `GameEnded`, `RoomUpdated`, `PlayerDisconnected`.

---

## 14. Known Issues / Not Yet Production-Ready (as of this document)

These are flagged so Cursor/Flutter dev doesn't assume they're fully working:

1. **`is_read` never updates.** `GET /friends/{friend_id}/messages` does not currently mark messages as read — unread counts in `/friends/conversations` may stay stuck. Backend fix pending.
2. **No cleanup job yet** for permanently deleting 7-day-old soft-deleted messages/voice files — storage will grow indefinitely until this is added.
3. **`wallet/topup` has no real payment verification.** It directly credits coins from whatever the client sends — must be wired to Google Play Billing / App Store receipt verification before public launch.
4. **`league_points` / `rank` only update on game completion / every 10 minutes respectively** — expect some lag between a match ending and the leaderboard/home screen reflecting it, especially if the Laravel scheduler cron isn't running on the deployment server.
5. **No live voice/video calling** — only asynchronous voice *notes* (record → upload → play later) are implemented, similar to WhatsApp. There is no real-time call feature.
6. **Exact WebSocket channel name for game events** (room vs game based) should be confirmed directly against the `GameStarted`/`TokenMoved`/etc. event classes in the codebase before wiring Flutter, as this document infers it from context rather than pasted event code.

---

## 15. Suggested Flutter Screen → API Mapping

| Screen | Endpoint(s) |
|---|---|
| Splash / Login | `POST /auth/guest`, `POST /auth/google`, `POST /auth/login`, `POST /auth/register` |
| Home | `GET /home` |
| Profile (view) | `GET /profile` |
| Profile (edit) | `PUT /profile` |
| Quick Match button | `POST /matchmaking/join` + listen `match.found` |
| Game board | `GET /game/state`, `POST /game/roll`, `POST /game/move` + game WebSocket events |
| Store | `GET /store/items`, `POST /store/purchase`, `GET /store/inventory` |
| Wallet | `GET /wallet/balance`, `GET /wallet/transactions`, `POST /wallet/topup` |
| Friends list | `GET /friends`, `POST /friends/request`, `POST /friends/{id}/respond` |
| Friend chat (DM) | `GET /friends/conversations`, `GET /friends/{friend_id}/messages`, `POST /friends/{friend_id}/message`, `DELETE /friends/messages/{message_id}` + WebSocket `direct.message.*` |
| Leaderboard | `GET /leaderboard` |
| Room-based in-game chat | `POST /chat/message`, `GET /chat/messages` |

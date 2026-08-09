<?php

namespace Tests\Feature;

use App\Enums\FriendStatus;
use App\Events\DirectMessageDeleted;
use App\Events\DirectMessageSent;
use App\Models\DirectMessage;
use App\Models\Friend;
use App\Models\User;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
        Storage::fake('public');
    }

    private function createFriendship(User $user1, User $user2): void
    {
        Friend::create([
            'user_id' => $user1->id,
            'friend_id' => $user2->id,
            'status' => FriendStatus::ACCEPTED->value,
            'created_at' => now(),
        ]);
    }

    public function test_send_text_message_between_friends(): void
    {
        Event::fake([DirectMessageSent::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'text',
            'message' => 'Hello friend!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.message', 'Hello friend!')
            ->assertJsonPath('data.voice_url', null)
            ->assertJsonPath('data.voice_duration', null);

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $user1->id,
            'receiver_id' => $user2->id,
            'type' => 'text',
            'message' => 'Hello friend!',
        ]);

        Event::assertDispatched(DirectMessageSent::class);
    }

    public function test_send_voice_note_message_between_friends(): void
    {
        Event::fake([DirectMessageSent::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        $audioFile = UploadedFile::fake()->create('voice_note.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $audioFile,
            'voice_duration' => 12,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.voice_duration', 12);

        $voiceUrl = $response->json('data.voice_url');
        $this->assertNotNull($voiceUrl);
        $this->assertStringContainsString('/storage/voice_messages/', $voiceUrl);

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $user1->id,
            'receiver_id' => $user2->id,
            'type' => 'voice',
            'voice_duration' => 12,
        ]);

        Event::assertDispatched(DirectMessageSent::class);
    }

    public function test_voice_note_rejects_non_audio_mime_type(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // Upload a text file with .mp3 extension (MIME type remains text/plain)
        $fakeAudio = UploadedFile::fake()->create('hacked.mp3', 100, 'text/plain');

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $fakeAudio,
            'voice_duration' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('voice_note');
    }

    public function test_voice_note_rejects_oversized_audio_file(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // Create 6MB file (exceeds 5MB limit)
        $largeAudio = UploadedFile::fake()->create('large.mp3', 6144, 'audio/mpeg');

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $largeAudio,
            'voice_duration' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('voice_note');
    }

    public function test_voice_note_rejects_missing_voice_duration(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        $audioFile = UploadedFile::fake()->create('voice.mp3', 500, 'audio/mpeg');

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $audioFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('voice_duration');
    }

    public function test_non_friends_cannot_send_messages(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        // No friendship created

        $response = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'text',
            'message' => 'Hello stranger',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'You can only send messages to accepted friends');
    }

    public function test_conversations_preview_shows_voice_message_label(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // Send a voice message
        $audioFile = UploadedFile::fake()->create('voice.mp3', 500, 'audio/mpeg');
        $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $audioFile,
            'voice_duration' => 8,
        ]);

        $response = $this->actingAs($user1)->getJson('/api/v1/friends/conversations');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.last_message', '🎤 Voice message')
            ->assertJsonPath('data.0.last_message_type', 'voice');
    }

    public function test_sender_can_soft_delete_own_message_and_file_is_retained(): void
    {
        Event::fake([DirectMessageDeleted::class]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // Send voice note
        $audioFile = UploadedFile::fake()->create('voice.mp3', 500, 'audio/mpeg');
        $res = $this->actingAs($user1)->postJson("/api/v1/friends/{$user2->id}/message", [
            'type' => 'voice',
            'voice_note' => $audioFile,
            'voice_duration' => 5,
        ]);
        $messageId = $res->json('data.id');
        $voiceUrl = $res->json('data.voice_url');
        $path = str_replace('/storage/', '', $voiceUrl);

        // Delete message
        $deleteRes = $this->actingAs($user1)->deleteJson("/api/v1/friends/messages/{$messageId}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('message', 'Message deleted successfully');

        // Confirm soft deleted in DB (deleted_at is set)
        $msg = DirectMessage::withTrashed()->find($messageId);
        $this->assertNotNull($msg->deleted_at);

        // Confirm audio file is retained on storage (not deleted immediately)
        Storage::disk('public')->assertExists($path);

        Event::assertDispatched(DirectMessageDeleted::class);
    }

    public function test_non_sender_cannot_delete_message(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        $msg = DirectMessage::create([
            'sender_id' => $user1->id,
            'receiver_id' => $user2->id,
            'type' => 'text',
            'message' => 'Sender message',
            'created_at' => now(),
        ]);

        // Receiver (user2) attempts to delete user1's message
        $response = $this->actingAs($user2)->deleteJson("/api/v1/friends/messages/{$msg->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'You can only delete your own messages');
    }

    public function test_deleted_messages_do_not_appear_in_history_or_conversations_preview(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // Message 1 (older, valid)
        $msg1 = DirectMessage::create([
            'sender_id' => $user1->id,
            'receiver_id' => $user2->id,
            'type' => 'text',
            'message' => 'First message',
            'created_at' => now()->subMinutes(10),
        ]);

        // Message 2 (newer, will be deleted)
        $msg2 = DirectMessage::create([
            'sender_id' => $user1->id,
            'receiver_id' => $user2->id,
            'type' => 'text',
            'message' => 'Second message to be deleted',
            'created_at' => now(),
        ]);

        // Soft delete message 2
        $msg2->delete();

        // Check messages list (should only include message 1)
        $historyRes = $this->actingAs($user1)->getJson("/api/v1/friends/{$user2->id}/messages");
        $historyRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $msg1->id)
            ->assertJsonPath('data.0.message', 'First message');

        // Check conversations preview (should fall back to message 1)
        $convRes = $this->actingAs($user1)->getJson('/api/v1/friends/conversations');
        $convRes->assertStatus(200)
            ->assertJsonPath('data.0.last_message', 'First message');
    }

    public function test_fetching_messages_marks_incoming_unread_messages_as_read(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->createFriendship($user1, $user2);

        // User2 sends 2 unread messages to User1
        DirectMessage::create([
            'sender_id' => $user2->id,
            'receiver_id' => $user1->id,
            'type' => 'text',
            'message' => 'Hey User1!',
            'is_read' => false,
            'created_at' => now()->subMinutes(2),
        ]);

        DirectMessage::create([
            'sender_id' => $user2->id,
            'receiver_id' => $user1->id,
            'type' => 'text',
            'message' => 'Are you online?',
            'is_read' => false,
            'created_at' => now(),
        ]);

        // Confirm unread_count is 2 before reading
        $convResBefore = $this->actingAs($user1)->getJson('/api/v1/friends/conversations');
        $convResBefore->assertStatus(200)
            ->assertJsonPath('data.0.unread_count', 2);

        // User1 fetches message thread with User2
        $messagesRes = $this->actingAs($user1)->getJson("/api/v1/friends/{$user2->id}/messages");
        $messagesRes->assertStatus(200)
            ->assertJsonPath('data.0.is_read', true)
            ->assertJsonPath('data.1.is_read', true);

        // Confirm DB records are updated
        $this->assertEquals(0, DirectMessage::where('receiver_id', $user1->id)->where('is_read', false)->count());

        // Confirm unread_count is now 0 in conversations list
        $convResAfter = $this->actingAs($user1)->getJson('/api/v1/friends/conversations');
        $convResAfter->assertStatus(200)
            ->assertJsonPath('data.0.unread_count', 0);
    }
}

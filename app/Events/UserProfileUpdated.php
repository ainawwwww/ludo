<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserProfileUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public array $friendUserIds
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('user.' . $this->user->id)];
        foreach ($this->friendUserIds as $friendId) {
            $channels[] = new PrivateChannel('user.' . $friendId);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'user.profile_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'avatar_url' => $this->user->avatar_url,
            'updated_at' => $this->user->updated_at?->toIso8601String(),
        ];
    }
}

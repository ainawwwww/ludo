<?php

namespace App\Events;

use App\Models\DirectMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DirectMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DirectMessage $directMessage
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->directMessage->receiver_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'direct.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->directMessage->id,
            'sender_id' => $this->directMessage->sender_id,
            'receiver_id' => $this->directMessage->receiver_id,
            'type' => $this->directMessage->type,
            'message' => $this->directMessage->message,
            'voice_url' => $this->directMessage->voice_url,
            'voice_duration' => $this->directMessage->voice_duration,
            'created_at' => $this->directMessage->created_at?->toIso8601String(),
        ];
    }
}

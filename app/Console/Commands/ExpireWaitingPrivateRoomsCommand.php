<?php

namespace App\Console\Commands;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Console\Command;

class ExpireWaitingPrivateRoomsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'private-rooms:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire and cancel stale waiting private rooms';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ttlMinutes = (int) config('private_room.waiting_ttl_minutes', 30);
        $cutoff = now()->subMinutes($ttlMinutes);

        $expiredRooms = Room::whereIn('type', [RoomType::PRIVATE, RoomType::VIP])
            ->where('status', RoomStatus::WAITING)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $count = 0;
        $privateRoomService = app(\App\Services\PrivateRoomService::class);
        foreach ($expiredRooms as $room) {
            $room->status = RoomStatus::CANCELLED;
            $room->state_version = ((int) $room->state_version) + 1;
            $room->save();

            $publicSnapshot = $privateRoomService->publicSnapshot($room);
            \App\Events\PrivateRoomUpdated::dispatch(
                $room->id,
                'expired',
                null,
                $publicSnapshot,
                (int) $room->state_version
            );

            $count++;
        }

        $this->info("Expired {$count} stale waiting private rooms.");
        return self::SUCCESS;
    }
}

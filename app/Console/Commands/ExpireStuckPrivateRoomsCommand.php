<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Console\Command;

class ExpireStuckPrivateRoomsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'private-rooms:expire-stuck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finish playing private rooms whose game is finished or has had no activity for playing_stuck_hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stuckHours = (int) config('private_room.playing_stuck_hours', 3);
        $cutoff = now()->subHours($stuckHours);

        // Find playing rooms that have completed games OR have been inactive for $stuckHours
        $stuckRooms = Room::where('type', RoomType::PRIVATE)
            ->where('status', RoomStatus::PLAYING)
            ->where(function ($query) use ($cutoff) {
                $query->whereHas('games', function ($q) {
                    $q->where('status', GameStatus::COMPLETED->value);
                })
                ->orWhere('updated_at', '<=', $cutoff);
            })
            ->get();

        $count = 0;
        $privateRoomService = app(\App\Services\PrivateRoomService::class);
        foreach ($stuckRooms as $room) {
            $room->status = RoomStatus::FINISHED;
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

        $this->info("Finished {$count} stuck playing private rooms.");
        return self::SUCCESS;
    }
}

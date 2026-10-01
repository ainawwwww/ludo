<?php

namespace Database\Seeders;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SocialRoomSeeder extends Seeder
{
    /**
     * Seed 12 live social/chat rooms with realistic data.
     * Each room gets a host (seat 1) and 0-2 additional members.
     */
    public function run(): void
    {
        $rooms = [
            [
                'title' => 'MADRID🇪🇸 🇵🇰',
                'category' => 'social',
                'tags' => ['BIENVENIDOS / WELCOME TO MAD...', 'MADRID', 'VIP'],
                'country_code' => 'PK',
                'member_count' => 32,
                'extra_members' => 1,
            ],
            [
                'title' => 'OPEN MINDED_🔥',
                'category' => 'social',
                'tags' => ['feel me bebe', 'LateNight'],
                'country_code' => 'PK',
                'member_count' => 15,
                'extra_members' => 0,
            ],
            [
                'title' => 'PUNJAB CAFE🖤',
                'category' => 'friends',
                'tags' => ['ہم اپنی ریاست کے نواب لوگ', 'Punjab', 'Dosti'],
                'country_code' => 'PK',
                'member_count' => 13,
                'extra_members' => 0,
            ],
            [
                'title' => '❤️Diamond Heart❤️',
                'category' => 'chat',
                'tags' => ['WElLcOME EvErYOne DiaMonD', 'ROCKSTAR', 'GAMING ROOM'],
                'country_code' => 'PK',
                'member_count' => 19,
                'extra_members' => 1,
            ],
            [
                'title' => 'Pakistan Lounge & Dosti Point 🔥',
                'category' => 'social',
                'tags' => ['Desi Friends', 'Masti', 'Chai'],
                'country_code' => 'PK',
                'member_count' => 34,
                'extra_members' => 2,
            ],
            [
                'title' => 'Friends Masti 🎉',
                'category' => 'music',
                'tags' => ['Party Music', 'Bollywood', 'Beats'],
                'country_code' => 'PK',
                'member_count' => 104,
                'extra_members' => 2,
            ],
            [
                'title' => 'Ludo Masters Club 🎲',
                'category' => 'chat',
                'tags' => ['LudoPro', 'Championship', 'India'],
                'country_code' => 'IN',
                'member_count' => 220,
                'extra_members' => 2,
            ],
            [
                'title' => 'Delhi Chills & Beats ☕',
                'category' => 'social',
                'tags' => ['Delhi', 'Chai', 'Music'],
                'country_code' => 'IN',
                'member_count' => 65,
                'extra_members' => 1,
            ],
            [
                'title' => 'Riyadh VIP Lounge 👑',
                'category' => 'social',
                'tags' => ['Riyadh', 'VIP', 'Coffee'],
                'country_code' => 'SA',
                'member_count' => 95,
                'extra_members' => 1,
            ],
            [
                'title' => 'Music Corner & Beats 🎧',
                'category' => 'music',
                'tags' => ['Dubai', 'Party', 'Acoustic'],
                'country_code' => 'AE',
                'member_count' => 180,
                'extra_members' => 2,
            ],
            [
                'title' => 'Dhaka Friendship Club 🇧🇩',
                'category' => 'friends',
                'tags' => ['Bangla', 'Friends', 'Fun'],
                'country_code' => 'BD',
                'member_count' => 74,
                'extra_members' => 1,
            ],
            [
                'title' => 'Night Owls Ludo 🦉',
                'category' => 'social',
                'tags' => ['LateNight', 'Chill', 'Gaming'],
                'country_code' => 'PK',
                'member_count' => 48,
                'extra_members' => 1,
            ],
        ];

        // Use existing users as hosts/members (round-robin from user IDs 1-20)
        $userIds = User::orderBy('id')->pluck('id')->take(20)->toArray();
        if (empty($userIds)) {
            $this->command->warn('No users found in database. Skipping social room seeder.');
            return;
        }

        $userIndex = 0;
        $colorMap = [
            1 => PlayerColor::RED->value,
            2 => PlayerColor::GREEN->value,
            3 => PlayerColor::YELLOW->value,
            4 => PlayerColor::BLUE->value,
        ];

        foreach ($rooms as $roomData) {
            // Pick host user
            $hostId = $userIds[$userIndex % count($userIds)];
            $userIndex++;

            $room = Room::create([
                'room_code' => strtoupper(Str::random(6)),
                'title' => $roomData['title'],
                'category' => $roomData['category'],
                'tags' => $roomData['tags'],
                'country_code' => $roomData['country_code'],
                'cover_image' => null,
                'member_count' => $roomData['member_count'],
                'is_live' => true,
                'type' => RoomType::PUBLIC->value,
                'max_players' => 8,
                'entry_fee' => 0,
                'status' => RoomStatus::WAITING->value,
                'created_by' => $hostId,
                'created_at' => now(),
            ]);

            // Add host as seat 1
            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $hostId,
                'seat_position' => 1,
                'color' => $colorMap[1],
                'is_ready' => true,
                'joined_at' => now(),
            ]);

            // Add extra members
            for ($i = 0; $i < $roomData['extra_members']; $i++) {
                $memberId = $userIds[$userIndex % count($userIds)];
                $userIndex++;
                $seatPos = $i + 2;

                // Skip if same user as host
                if ($memberId === $hostId) {
                    $userIndex++;
                    $memberId = $userIds[$userIndex % count($userIds)];
                }

                RoomPlayer::create([
                    'room_id' => $room->id,
                    'user_id' => $memberId,
                    'seat_position' => $seatPos,
                    'color' => $colorMap[$seatPos] ?? PlayerColor::RED->value,
                    'is_ready' => true,
                    'joined_at' => now(),
                ]);
            }

            $this->command->info("✅ Created room: {$roomData['title']} (ID: {$room->id})");
        }

        $this->command->info("\n🎉 Seeded " . count($rooms) . " social rooms successfully!");
    }
}

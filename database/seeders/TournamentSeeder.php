<?php

namespace Database\Seeders;

use App\Models\Tournament;
use App\Models\TournamentLevel;
use Illuminate\Database\Seeder;

class TournamentSeeder extends Seeder
{
    /**
     * Seed the tournaments and tournament_levels tables.
     */
    public function run(): void
    {
        $tournaments = [
            [
                'name' => 'Classic-20.0K',
                'mode' => 'classic',
                'entry_fee' => 20000,
                'currency_type' => 'coins',
                'prize_pool' => 250000,
                'max_level' => 6,
                'unlock_level' => 1,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 2500, 'diamonds' => 0],
                    2 => ['coins' => 5000, 'diamonds' => 0],
                    3 => ['coins' => 10000, 'diamonds' => 0],
                    4 => ['coins' => 20000, 'diamonds' => 0],
                    5 => ['coins' => 35000, 'diamonds' => 0],
                    6 => ['coins' => 75000, 'diamonds' => 5],
                ],
            ],
            [
                'name' => 'Quick-20.0K',
                'mode' => 'quick',
                'entry_fee' => 20000,
                'currency_type' => 'coins',
                'prize_pool' => 200000,
                'max_level' => 6,
                'unlock_level' => 1,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 2000, 'diamonds' => 0],
                    2 => ['coins' => 4000, 'diamonds' => 0],
                    3 => ['coins' => 8000, 'diamonds' => 0],
                    4 => ['coins' => 16000, 'diamonds' => 0],
                    5 => ['coins' => 30000, 'diamonds' => 0],
                    6 => ['coins' => 60000, 'diamonds' => 5],
                ],
            ],
            [
                'name' => 'Classic-5.0K',
                'mode' => 'classic',
                'entry_fee' => 5000,
                'currency_type' => 'coins',
                'prize_pool' => 60000,
                'max_level' => 6,
                'unlock_level' => 3,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 600, 'diamonds' => 0],
                    2 => ['coins' => 1200, 'diamonds' => 0],
                    3 => ['coins' => 2500, 'diamonds' => 0],
                    4 => ['coins' => 5000, 'diamonds' => 0],
                    5 => ['coins' => 9000, 'diamonds' => 0],
                    6 => ['coins' => 20000, 'diamonds' => 2],
                ],
            ],
            [
                'name' => 'Quick-5.0K',
                'mode' => 'quick',
                'entry_fee' => 5000,
                'currency_type' => 'coins',
                'prize_pool' => 50000,
                'max_level' => 6,
                'unlock_level' => 5,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 500, 'diamonds' => 0],
                    2 => ['coins' => 1000, 'diamonds' => 0],
                    3 => ['coins' => 2000, 'diamonds' => 0],
                    4 => ['coins' => 4000, 'diamonds' => 0],
                    5 => ['coins' => 7500, 'diamonds' => 0],
                    6 => ['coins' => 16000, 'diamonds' => 2],
                ],
            ],
            [
                'name' => 'Classic-600',
                'mode' => 'classic',
                'entry_fee' => 600,
                'currency_type' => 'coins',
                'prize_pool' => 2400,
                'max_level' => 6,
                'unlock_level' => 1,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 50, 'diamonds' => 0],
                    2 => ['coins' => 100, 'diamonds' => 0],
                    3 => ['coins' => 200, 'diamonds' => 0],
                    4 => ['coins' => 300, 'diamonds' => 0],
                    5 => ['coins' => 450, 'diamonds' => 0],
                    6 => ['coins' => 600, 'diamonds' => 0],
                ],
            ],
        ];

        foreach ($tournaments as $data) {
            $levels = $data['levels'];
            unset($data['levels']);

            $tournament = Tournament::updateOrCreate(
                ['name' => $data['name']],
                $data
            );

            foreach ($levels as $level => $rewards) {
                TournamentLevel::updateOrCreate(
                    [
                        'tournament_id' => $tournament->id,
                        'level' => $level,
                    ],
                    [
                        'reward_coins' => $rewards['coins'],
                        'reward_diamonds' => $rewards['diamonds'],
                    ]
                );
            }
        }
    }
}

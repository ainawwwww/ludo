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
                'name' => 'Classic-600',
                'mode' => 'classic',
                'entry_fee' => 600,
                'currency_type' => 'coins',
                'prize_pool' => 2400,
                'max_level' => 6,
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
            [
                'name' => 'Classic-20000',
                'mode' => 'classic',
                'entry_fee' => 20000,
                'currency_type' => 'coins',
                'prize_pool' => 80000,
                'max_level' => 6,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 1000, 'diamonds' => 0],
                    2 => ['coins' => 2500, 'diamonds' => 0],
                    3 => ['coins' => 5000, 'diamonds' => 0],
                    4 => ['coins' => 10000, 'diamonds' => 0],
                    5 => ['coins' => 15000, 'diamonds' => 0],
                    6 => ['coins' => 20000, 'diamonds' => 0],
                ],
            ],
            [
                'name' => 'Quick-1000',
                'mode' => 'quick',
                'entry_fee' => 1000,
                'currency_type' => 'coins',
                'prize_pool' => 4000,
                'max_level' => 6,
                'status' => 'active',
                'levels' => [
                    1 => ['coins' => 100, 'diamonds' => 0],
                    2 => ['coins' => 200, 'diamonds' => 0],
                    3 => ['coins' => 350, 'diamonds' => 0],
                    4 => ['coins' => 500, 'diamonds' => 0],
                    5 => ['coins' => 750, 'diamonds' => 0],
                    6 => ['coins' => 1000, 'diamonds' => 0],
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

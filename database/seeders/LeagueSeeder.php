<?php

namespace Database\Seeders;

use App\Models\League;
use Illuminate\Database\Seeder;

class LeagueSeeder extends Seeder
{
    /**
     * Seed the leagues table with 5 tiers.
     */
    public function run(): void
    {
        $leagues = [
            [
                'name' => 'Bronze',
                'min_points' => 0,
                'max_points' => 999,
                'icon_url' => '/images/leagues/bronze.png',
                'tier_order' => 1,
            ],
            [
                'name' => 'Silver',
                'min_points' => 1000,
                'max_points' => 2499,
                'icon_url' => '/images/leagues/silver.png',
                'tier_order' => 2,
            ],
            [
                'name' => 'Gold',
                'min_points' => 2500,
                'max_points' => 4999,
                'icon_url' => '/images/leagues/gold.png',
                'tier_order' => 3,
            ],
            [
                'name' => 'Platinum',
                'min_points' => 5000,
                'max_points' => 9999,
                'icon_url' => '/images/leagues/platinum.png',
                'tier_order' => 4,
            ],
            [
                'name' => 'Diamond',
                'min_points' => 10000,
                'max_points' => 999999,
                'icon_url' => '/images/leagues/diamond.png',
                'tier_order' => 5,
            ],
        ];

        foreach ($leagues as $league) {
            League::updateOrCreate(
                ['name' => $league['name']],
                $league
            );
        }
    }
}

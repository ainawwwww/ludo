<?php

namespace Tests\Feature;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserInventory;
use Database\Seeders\LeagueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteDiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LeagueSeeder::class);
    }

    public function test_profile_shows_equipped_dice_skin_name(): void
    {
        $user = User::factory()->create();

        $diceItem = StoreItem::create([
            'name' => 'Golden Dragon Dice',
            'type' => 'dice_skin',
            'price' => 500,
            'currency_type' => 'coins',
            'image_url' => '/images/dice/golden_dragon.png',
            'is_active' => true,
            'created_at' => now(),
        ]);

        UserInventory::create([
            'user_id' => $user->id,
            'item_id' => $diceItem->id,
            'is_equipped' => true,
            'purchased_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.achievements.favorite_dice', 'Golden Dragon Dice');
    }

    public function test_profile_shows_null_when_no_dice_skin_equipped(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.achievements.favorite_dice', null);
    }
}

<?php

namespace Tests\Unit;

use App\Models\Room;
use App\Models\User;
use App\Services\RoomCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected RoomCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RoomCodeGenerator();
    }

    public function test_generate_returns_exact_length_with_safe_charset(): void
    {
        $code = $this->generator->generate(6);

        $this->assertEquals(6, strlen($code));
        // Must contain only characters from safe charset
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $code);
        // Must NOT contain ambiguous characters 0, 1, I, L, O
        $this->assertDoesNotMatchRegularExpression('/[01ILO]/', $code);
    }

    public function test_generate_unique_avoids_existing_room_codes(): void
    {
        $user = User::factory()->create();

        // Pre-create room with code
        $existingCode = $this->generator->generate(6);
        Room::create([
            'room_code' => $existingCode,
            'max_players' => 2,
            'entry_fee' => 0,
            'status' => 'waiting',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $newCode = $this->generator->generateUnique(6);
        $this->assertNotEquals($existingCode, $newCode);
    }

    public function test_is_valid_accepts_valid_alphanumeric_codes_and_rejects_invalid(): void
    {
        // Valid safe charset codes
        $this->assertTrue($this->generator->isValid('ABC234'));
        $this->assertTrue($this->generator->isValid('XYZ987'));

        // Legacy codes containing 0, 1, I, O are also accepted for backward compatibility
        $this->assertTrue($this->generator->isValid('ROOM01'));
        $this->assertTrue($this->generator->isValid('ABC123'));

        // Case insensitivity
        $this->assertTrue($this->generator->isValid('abc123'));

        // Invalid: wrong length
        $this->assertFalse($this->generator->isValid('ABC12'));
        $this->assertFalse($this->generator->isValid('ABC1234'));

        // Invalid: special characters
        $this->assertFalse($this->generator->isValid('ABC-12'));
        $this->assertFalse($this->generator->isValid('ABC 12'));
    }
}

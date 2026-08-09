<?php

namespace Tests\Unit;

use App\Services\GameEngine\BoardService;
use App\Services\GameEngine\DiceService;
use App\Services\GameEngine\MoveValidator;
use App\Services\GameEngine\TurnManager;
use PHPUnit\Framework\TestCase;

class GameEngineTest extends TestCase
{
    private DiceService $diceService;
    private BoardService $boardService;
    private TurnManager $turnManager;
    private MoveValidator $moveValidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diceService = new DiceService();
        $this->boardService = new BoardService();
        $this->turnManager = new TurnManager();
        $this->moveValidator = new MoveValidator($this->boardService);
    }

    public function test_dice_roll_returns_value_between_1_and_6(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $roll = $this->diceService->roll();
            $this->assertGreaterThanOrEqual(1, $roll);
            $this->assertLessThanOrEqual(6, $roll);
        }
    }

    public function test_dice_forced_roll_returns_exact_value(): void
    {
        $this->diceService->forceRoll(6);
        $this->assertEquals(6, $this->diceService->roll());
    }

    public function test_board_service_color_offsets_and_safe_spots(): void
    {
        $this->assertEquals(0, $this->boardService->getStartOffset('red'));
        $this->assertEquals(13, $this->boardService->getStartOffset('green'));
        $this->assertEquals(26, $this->boardService->getStartOffset('yellow'));
        $this->assertEquals(39, $this->boardService->getStartOffset('blue'));

        $this->assertTrue($this->boardService->isSafeSpot(0));
        $this->assertTrue($this->boardService->isSafeSpot(13));
        $this->assertTrue($this->boardService->isSafeSpot(8)); // star
        $this->assertFalse($this->boardService->isSafeSpot(5));
    }

    public function test_board_position_calculation(): void
    {
        // Base
        $pos = $this->boardService->calculatePosition('red', -1);
        $this->assertEquals('base', $pos['type']);

        // Start tile
        $pos = $this->boardService->calculatePosition('red', 0);
        $this->assertEquals(0, $pos['global_pos']);
        $this->assertTrue($pos['is_safe']);

        // Green start tile (steps 0 for green = global 13)
        $pos = $this->boardService->calculatePosition('green', 0);
        $this->assertEquals(13, $pos['global_pos']);

        // Home destination (56 steps)
        $pos = $this->boardService->calculatePosition('red', 56);
        $this->assertEquals('home', $pos['type']);
    }

    public function test_turn_manager_rotation_and_extra_turn(): void
    {
        $activeSeats = [0, 1, 2, 3];

        // Normal rotation
        $next = $this->turnManager->getNextTurn(0, $activeSeats, false);
        $this->assertEquals(1, $next);

        $next = $this->turnManager->getNextTurn(3, $activeSeats, false);
        $this->assertEquals(0, $next);

        // Extra turn keeps current seat
        $next = $this->turnManager->getNextTurn(2, $activeSeats, true);
        $this->assertEquals(2, $next);
    }

    public function test_consecutive_sixes_forfeit_extra_turn(): void
    {
        $this->assertTrue($this->turnManager->shouldGrantExtraTurn(6, false, false, 1));
        $this->assertTrue($this->turnManager->shouldGrantExtraTurn(6, false, false, 2));

        // 3rd consecutive six forfeits extra turn
        $this->assertFalse($this->turnManager->shouldGrantExtraTurn(6, false, false, 3));
    }

    public function test_move_validator_exit_base_requires_6(): void
    {
        $tokens = [
            'red' => [-1, -1, -1, -1],
            'green' => [-1, -1, -1, -1],
        ];

        // Roll 4 -> Invalid move from base
        $res = $this->moveValidator->validateMove($tokens, 'red', 0, 4);
        $this->assertFalse($res['is_valid']);

        // Roll 6 -> Exits base to start pos (0 steps)
        $res = $this->moveValidator->validateMove($tokens, 'red', 0, 6);
        $this->assertTrue($res['is_valid']);
        $this->assertEquals(0, $res['new_steps']);
    }

    public function test_move_validator_overshoot_disallowed(): void
    {
        $tokens = [
            'red' => [54, -1, -1, -1],
        ];

        // From 54, rolling 5 = 59 > 56 -> Invalid
        $res = $this->moveValidator->validateMove($tokens, 'red', 0, 5);
        $this->assertFalse($res['is_valid']);

        // Rolling 2 = 56 (Home) -> Valid
        $res = $this->moveValidator->validateMove($tokens, 'red', 0, 2);
        $this->assertTrue($res['is_valid']);
        $this->assertEquals(56, $res['new_steps']);
    }

    public function test_move_validator_captures_opponent_token(): void
    {
        // Red starting offset: 0. Step 5 for Red = Global 5.
        // Green starting offset: 13. Step 44 for Green = (13+44)%52 = Global 5.
        $tokens = [
            'red' => [4, -1, -1, -1],
            'green' => [44, -1, -1, -1], // Green token at global pos 5 (not a safe spot)
        ];

        // Red at step 4 rolls 1 -> moves to step 5 (global pos 5).
        $res = $this->moveValidator->validateMove($tokens, 'red', 0, 1);
        $this->assertTrue($res['is_valid']);
        $this->assertTrue($res['is_kill']);
        $this->assertCount(1, $res['killed_tokens']);

        $killed = $res['killed_tokens'][0];
        $this->assertEquals('green', $killed['color']);
        $this->assertEquals(0, $killed['token_index']);
        $this->assertEquals(-1, $killed['new_steps']); // Sent back to base
    }

    public function test_move_validator_detects_win_condition(): void
    {
        // 3 tokens already home (56), 4th token at 54
        $tokens = [
            'red' => [56, 56, 56, 54],
        ];

        // Roll 2 to move 4th token to 56
        $res = $this->moveValidator->validateMove($tokens, 'red', 3, 2);
        $this->assertTrue($res['is_valid']);
        $this->assertTrue($res['has_won']);
    }
}

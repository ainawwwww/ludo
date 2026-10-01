<?php

namespace Tests\Unit;

use App\Services\GameEngine\MoveValidator;
use PHPUnit\Framework\TestCase;

class TeamModeEngineTest extends TestCase
{
    protected MoveValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MoveValidator();
    }

    public static function teamModeVectorProvider(): array
    {
        $fixturePath = __DIR__ . '/../Fixtures/team_mode_engine_vectors.json';
        $jsonContent = file_get_contents($fixturePath);
        $fixture = json_decode($jsonContent, true);

        $cases = [];
        foreach ($fixture['test_cases'] as $case) {
            $cases[$case['id']] = [$case];
        }
        return $cases;
    }

    /**
     * @dataProvider teamModeVectorProvider
     */
    public function test_evaluates_team_mode_vector(array $testCase): void
    {
        $caseId = $testCase['id'];
        $roomType = $testCase['room_type'] ?? 'team';
        $movingColor = $testCase['moving_color'];
        $tokens = $testCase['tokens'];
        $expected = $testCase['expected'];

        if (isset($expected['should_skip_turn'])) {
            $playerTokens = $tokens[$movingColor];
            $isFinished = $this->validator->isPlayerFinished($playerTokens);
            $shouldSkip = $this->validator->shouldSkipTurn($playerTokens);
            $movable = $this->validator->getMovableTokens($playerTokens, (int) ($testCase['dice_value'] ?? 6));

            $this->assertEquals($expected['is_finished'], $isFinished, "Case {$caseId}: is_finished mismatch");
            $this->assertEquals($expected['should_skip_turn'], $shouldSkip, "Case {$caseId}: should_skip_turn mismatch");
            $this->assertEquals($expected['movable_tokens'], $movable, "Case {$caseId}: movable_tokens mismatch");
            return;
        }

        $tokenIndex = (int) $testCase['token_index'];
        $diceValue = (int) $testCase['dice_value'];

        $result = $this->validator->validateMove(
            $tokens,
            $movingColor,
            $tokenIndex,
            $diceValue,
            $roomType
        );

        $this->assertEquals(
            $expected['is_valid'],
            $result['is_valid'],
            "Test case {$caseId} failed on is_valid"
        );

        $this->assertEquals(
            $expected['new_steps'],
            $result['new_steps'],
            "Test case {$caseId} failed on new_steps"
        );

        if (isset($expected['is_kill'])) {
            $this->assertEquals(
                $expected['is_kill'],
                $result['is_kill'],
                "Test case {$caseId} failed on is_kill"
            );
        }

        if (isset($expected['killed_tokens'])) {
            $this->assertCount(
                count($expected['killed_tokens']),
                $result['killed_tokens'],
                "Test case {$caseId} failed on killed_tokens count"
            );

            foreach ($expected['killed_tokens'] as $kIdx => $expKilled) {
                $actKilled = $result['killed_tokens'][$kIdx];
                $this->assertEquals($expKilled['color'], $actKilled['color']);
                $this->assertEquals($expKilled['token_index'], $actKilled['token_index']);
                $this->assertEquals($expKilled['new_steps'], $actKilled['new_steps']);
            }
        }

        if (isset($expected['has_won'])) {
            $this->assertEquals(
                $expected['has_won'],
                $result['has_won'],
                "Test case {$caseId} failed on has_won"
            );
        }
    }
}

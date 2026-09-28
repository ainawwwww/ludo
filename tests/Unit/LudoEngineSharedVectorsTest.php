<?php

namespace Tests\Unit;

use App\Services\GameEngine\BoardService;
use App\Services\GameEngine\MoveValidator;
use PHPUnit\Framework\TestCase;

class LudoEngineSharedVectorsTest extends TestCase
{
    private BoardService $boardService;
    private MoveValidator $moveValidator;
    private array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boardService = new BoardService();
        $this->moveValidator = new MoveValidator($this->boardService);

        $jsonPath = __DIR__ . '/../fixtures/ludo_engine_test_vectors.json';
        $this->assertFileExists($jsonPath);
        $this->fixtures = json_decode(file_get_contents($jsonPath), true);
    }

    public function test_shared_vectors_rules(): void
    {
        $rules = $this->fixtures['rules'];
        $this->assertEquals(BoardService::POSITION_HOME, $rules['total_steps_to_finish']);
        $this->assertEquals(50, $rules['last_main_track_step']);
        $this->assertEquals(51, $rules['first_home_stretch_step']);
        $this->assertEquals(55, $rules['last_home_stretch_step']);
        $this->assertEquals(5, $rules['home_stretch_length']);
    }

    public function test_shared_vectors_test_cases(): void
    {
        foreach ($this->fixtures['test_cases'] as $case) {
            $color = $case['color'];
            $currentStep = $case['current_step'];
            $dice = $case['dice'];
            $expectedValid = $case['is_valid'];
            $expectedNewStep = $case['new_step'];
            $expectedFinished = $case['is_finished'];

            $tokens = [
                'red' => [-1, -1, -1, -1],
                'green' => [-1, -1, -1, -1],
                'yellow' => [-1, -1, -1, -1],
                'blue' => [-1, -1, -1, -1],
            ];
            $tokens[$color][0] = $currentStep;

            $result = $this->moveValidator->validateMove($tokens, $color, 0, $dice);

            $this->assertEquals(
                $expectedValid,
                $result['is_valid'],
                "Failed valid check for case: {$case['description']}"
            );

            if ($expectedValid) {
                $this->assertEquals(
                    $expectedNewStep,
                    $result['new_steps'],
                    "Failed new_steps for case: {$case['description']}"
                );
                $this->assertEquals(
                    $expectedFinished,
                    $result['reached_home'],
                    "Failed reached_home for case: {$case['description']}"
                );
            }
        }
    }
}

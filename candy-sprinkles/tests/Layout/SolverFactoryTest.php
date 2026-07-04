<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\CassowarySolver;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Sprinkles\Layout\SolverFactory;

final class SolverFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        parent::tearDown();
    }

    public function testDefaultReturnsGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        $solver = SolverFactory::default();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testEnvCassowaryReturnsCassowarySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');

        // The factory emits a one-time E_USER_WARNING for the cassowary
        // opt-in. Capture it (so PHPUnit's failOnWarning doesn't fail the
        // suite) and assert it actually fired. The once-per-process flag is
        // reset first so this test is deterministic regardless of ordering.
        $flag = new \ReflectionProperty(SolverFactory::class, 'cassowaryWarningEmitted');
        $flag->setValue(null, false);

        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $solver = SolverFactory::default();
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(CassowarySolver::class, $solver);
        $this->assertIsString($warning);
        $this->assertStringContainsString('cassowary', strtolower($warning));
    }

    public function testEnvGreedyReturnsGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=greedy');
        $solver = SolverFactory::default();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testEnvGarbageDefaultsToGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=garbage');
        $solver = SolverFactory::default();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testEnvEmptyDefaultsToGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=""');
        $solver = SolverFactory::default();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }
}

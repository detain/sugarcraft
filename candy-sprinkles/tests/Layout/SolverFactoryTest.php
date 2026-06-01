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
        $solver = SolverFactory::default();
        $this->assertInstanceOf(CassowarySolver::class, $solver);
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

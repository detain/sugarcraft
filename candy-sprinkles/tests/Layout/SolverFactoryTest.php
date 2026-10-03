<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\CassowarySolver;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Sprinkles\Layout\Constraint;
use SugarCraft\Sprinkles\Layout\Direction;
use SugarCraft\Sprinkles\Layout\Rect;
use SugarCraft\Sprinkles\Layout\Solver;
use SugarCraft\Sprinkles\Layout\SolverFactory;

final class SolverFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        parent::tearDown();
    }

    public function testFromEnvironmentReturnsGreedySolverByDefault(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        $solver = SolverFactory::fromEnvironment();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    /**
     * Run $fn with every E_USER_WARNING / E_USER_DEPRECATED it raises
     * captured, the once-per-process flag reset first so the outcome does not
     * depend on test ordering.
     *
     * @return array{mixed, list<array{int, string}>}
     */
    private static function captureNotices(callable $fn): array
    {
        $flag = new \ReflectionProperty(SolverFactory::class, 'cassowaryNoticeEmitted');
        $flag->setValue(null, false);

        $raised = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$raised): bool {
            $raised[] = [$errno, $errstr];
            return true;
        }, E_USER_WARNING | E_USER_DEPRECATED);
        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }
        return [$result, $raised];
    }

    public function testEnvCassowaryReturnsCassowarySolverWithOneDeprecationNotice(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');

        [$solvers, $raised] = self::captureNotices(static fn(): array => [
            SolverFactory::fromEnvironment(),
            SolverFactory::fromEnvironment(),
        ]);

        $this->assertInstanceOf(CassowarySolver::class, $solvers[0]);
        $this->assertInstanceOf(CassowarySolver::class, $solvers[1]);
        // Exactly one notice across both calls, at DEPRECATION severity: the
        // opt-in no longer changes any result, so it must not be a warning
        // (which fails failOnWarning suites and warning-escalating hosts).
        $this->assertCount(1, $raised);
        [$errno, $message] = $raised[0];
        $this->assertSame(E_USER_DEPRECATED, $errno);
        $this->assertStringContainsString('SUGARCRAFT_LAYOUT_SOLVER=cassowary', $message);
        $this->assertStringContainsString('delegates every solve to GreedySolver', $message);
        // Not the long-fixed Ratio-returns-0 bug it used to cite.
        $this->assertStringNotContainsString('Ratio', $message);
        $this->assertStringNotContainsString('bug', strtolower($message));
    }

    public function testCassowaryOptInRaisesNoWarningEvenWhenItSolves(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');

        [, $raised] = self::captureNotices(static fn(): array => Solver::solve(
            new Rect(0, 0, 30, 1),
            [Constraint::ratio(1, 3), Constraint::fill()],
            Direction::Horizontal,
        ));

        $this->assertNotSame([], $raised);
        foreach ($raised as [$errno, $message]) {
            $this->assertSame(E_USER_DEPRECATED, $errno, $message);
        }
    }

    /**
     * The docblock's claim that the cassowary opt-in yields results identical
     * to the default — Ratio included, the constraint the old warning said
     * returned 0 — pinned through the public Solver facade.
     */
    public function testCassowaryOptInSolvesIdenticallyToGreedyIncludingRatio(): void
    {
        $area = new Rect(0, 0, 30, 1);
        $constraints = [Constraint::ratio(1, 3), Constraint::length(4), Constraint::fill()];

        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        $greedy = Solver::solve($area, $constraints, Direction::Horizontal);

        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');
        [$cassowary] = self::captureNotices(
            static fn(): array => Solver::solve($area, $constraints, Direction::Horizontal),
        );

        $this->assertEquals($greedy, $cassowary);
        $this->assertSame(10, $cassowary[0]->width, 'Ratio(1,3) of 30 is 10, not 0');
    }

    public function testEnvGreedyReturnsGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=greedy');
        $solver = SolverFactory::fromEnvironment();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testEnvGarbageDefaultsToGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=garbage');
        $solver = SolverFactory::fromEnvironment();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testEnvEmptyDefaultsToGreedySolver(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=""');
        $solver = SolverFactory::fromEnvironment();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testDeprecatedDefaultAliasForwardsToFromEnvironment(): void
    {
        // fromEnvironment() is the canonical name; default() survives the
        // rename as a @deprecated forwarder so dev-master consumers that
        // still call it do not fatal.
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        $this->assertInstanceOf(GreedySolver::class, SolverFactory::default());

        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');
        [[$viaAlias, $viaCanonical], $raised] = self::captureNotices(static fn(): array => [
            SolverFactory::default(),
            SolverFactory::fromEnvironment(),
        ]);
        $this->assertInstanceOf(CassowarySolver::class, $viaAlias);
        $this->assertInstanceOf(CassowarySolver::class, $viaCanonical);
        // Same once-per-process notice state: the alias shares it, not a copy.
        $this->assertCount(1, $raised);
        $this->assertSame(E_USER_DEPRECATED, $raised[0][0]);

        $doc = (string) (new \ReflectionMethod(SolverFactory::class, 'default'))->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString('fromEnvironment()', $doc);
    }
}

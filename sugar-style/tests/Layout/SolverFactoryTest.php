<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\CassowarySolver;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Style\Layout\Constraint;
use SugarCraft\Style\Layout\Direction;
use SugarCraft\Style\Layout\Rect;
use SugarCraft\Style\Layout\Solver;
use SugarCraft\Style\Layout\SolverFactory;

final class SolverFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // SUGARCRAFT_LAYOUT_SOLVER is resolved once per process (memoized
        // getenv); every test here re-arms the seam so its own putenv() is
        // the value that gets read.
        SolverFactory::resetEnvironmentResolutionForTesting();
    }

    protected function tearDown(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER');
        SolverFactory::resetEnvironmentResolutionForTesting();
        parent::tearDown();
    }

    public function testFromEnvironmentReturnsGreedySolverByDefault(): void
    {
        $solver = SolverFactory::fromEnvironment();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    /**
     * Performance-honesty pin (docs-fix-1023 style): the factory memoizes the
     * environment DECISION, never an instance — each call still hands out a
     * fresh solver, which is why the README may not claim "single
     * allocation". Deleting the `new` per call (instance caching) must go red
     * here and force a docs re-decision.
     */
    public function testEachCallConstructsAFreshSolverInstance(): void
    {
        $this->assertNotSame(SolverFactory::fromEnvironment(), SolverFactory::fromEnvironment());
    }

    /**
     * The memoize itself: a mid-process putenv must NOT be observed until
     * the test seam resets the resolution. Pins "read once" so a future
     * re-widening to per-call getenv has to re-decide this test too.
     */
    public function testEnvironmentDecisionIsResolvedOncePerProcessUntilReset(): void
    {
        putenv('SUGARCRAFT_LAYOUT_SOLVER=cassowary');
        [$solver] = self::captureNotices(static fn(): \SugarCraft\Constraints\LayoutSolver => SolverFactory::fromEnvironment());
        $this->assertInstanceOf(CassowarySolver::class, $solver);

        // Flip the variable; the memoized decision must ignore it...
        putenv('SUGARCRAFT_LAYOUT_SOLVER=greedy');
        $this->assertInstanceOf(CassowarySolver::class, $this->lastFromEnvironment());

        // ...until the seam re-arms resolution.
        SolverFactory::resetEnvironmentResolutionForTesting();
        $this->assertInstanceOf(GreedySolver::class, SolverFactory::fromEnvironment());
    }

    /** Resolve through fromEnvironment() and return it for instanceof checks. */
    private function lastFromEnvironment(): \SugarCraft\Constraints\LayoutSolver
    {
        // The cassowary branch may raise its once-per-process notice; swallow
        // it here — notice SEVERITY and count are pinned elsewhere.
        $previous = set_error_handler(static fn(): bool => true, E_USER_DEPRECATED | E_USER_WARNING);
        try {
            return SolverFactory::fromEnvironment();
        } finally {
            set_error_handler($previous);
        }
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
        SolverFactory::resetEnvironmentResolutionForTesting();
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
        SolverFactory::resetEnvironmentResolutionForTesting();
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

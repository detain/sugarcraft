<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Gravity;
use SugarCraft\Spring\Point;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Vector;
use PHPUnit\Framework\TestCase;

/**
 * SPR-21 (findings 050/080): the Gravity façade is a DEPRECATED alias set —
 * the canonical gravity home is the Projectile statics. These legs keep
 * every value assertion (the vectors must not drift while the door stays
 * open) but walk them under explicit deprecation discipline, and pin the
 * loudness: one dated notice per call naming the canonical replacement.
 */
final class GravityTest extends TestCase
{
    public function testStandardIsYUp(): void
    {
        $g = $this->silently(fn(): Vector => Gravity::standard());
        $this->assertSame(0.0, $g->x);
        $this->assertSame(-9.81, $g->y);
        $this->assertSame(0.0, $g->z);
    }

    public function testTerminalIsYUp(): void
    {
        $g = $this->silently(fn(): Vector => Gravity::terminal());
        $this->assertSame(0.0, $g->x);
        $this->assertSame(-53.0, $g->y);
    }

    public function testStandardYDownIsPositiveY(): void
    {
        $g = $this->silently(fn(): Vector => Gravity::standardYDown());
        $this->assertSame(9.81, $g->y);
    }

    public function testTerminalYDownIsPositiveY(): void
    {
        $g = $this->silently(fn(): Vector => Gravity::terminalYDown());
        $this->assertSame(53.0, $g->y);
    }

    /**
     * The fresh-per-call contract this file used to PIN is inverted by
     * SPR-21 (finding 080, disclosed): Vector is readonly, so per-call
     * construction was pure allocation, and the memoized shared instance
     * is the point. Identity — not just equality — across calls AND
     * through to the canonical Projectile door.
     */
    public function testAccessorsShareImmutableInstancesWithTheCanonicalHome(): void
    {
        $a = $this->silently(fn(): Vector => Gravity::standard());
        $b = $this->silently(fn(): Vector => Gravity::standard());
        $this->assertSame($a, $b, 'memoized: repeated facade calls hand back the same readonly vector');

        $this->assertSame(Projectile::gravity(), $a, 'facade and canonical door must not keep TWO vectors');
        $this->assertSame(Projectile::terminalGravity(), $this->silently(fn(): Vector => Gravity::terminal()));
        $this->assertSame(Projectile::gravityYDown(), $this->silently(fn(): Vector => Gravity::standardYDown()));
        $this->assertSame(Projectile::terminalGravityYDown(), $this->silently(fn(): Vector => Gravity::terminalYDown()));
    }

    public function testAliasesProjectileFactories(): void
    {
        $this->assertEquals(Projectile::gravity(), $this->silently(fn(): Vector => Gravity::standard()));
        $this->assertEquals(Projectile::terminalGravity(), $this->silently(fn(): Vector => Gravity::terminal()));
        $this->assertEquals(Projectile::gravityYDown(), $this->silently(fn(): Vector => Gravity::standardYDown()));
        $this->assertEquals(Projectile::terminalGravityYDown(), $this->silently(fn(): Vector => Gravity::terminalYDown()));
    }

    /**
     * @dataProvider deprecatedFacadeCalls
     */
    public function testDeprecatedFacadeEmitsDatedLoudness(callable $call, string $canonical): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = [$errno, $errstr];
            return true;
        }, E_USER_DEPRECATED);
        try {
            $call();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'facade accessor must emit exactly one deprecation');
        $this->assertSame(E_USER_DEPRECATED, $captured[0][0]);
        $this->assertStringContainsStringIgnoringCase('deprecated', $captured[0][1]);
        $this->assertStringContainsString($canonical, $captured[0][1], 'notice must name the canonical replacement');
        $this->assertStringContainsString('REMOVED-IN: 2.0.0', $captured[0][1], 'one expiry vocabulary across the whole lib');
    }

    /** @return array<string, array{callable, string}> */
    public static function deprecatedFacadeCalls(): array
    {
        return [
            'standard' => [static fn(): Vector => Gravity::standard(), 'Projectile::gravity()'],
            'terminal' => [static fn(): Vector => Gravity::terminal(), 'Projectile::terminalGravity()'],
            'standardYDown' => [static fn(): Vector => Gravity::standardYDown(), 'Projectile::gravityYDown()'],
            'terminalYDown' => [static fn(): Vector => Gravity::terminalYDown(), 'Projectile::terminalGravityYDown()'],
        ];
    }

    public function testReturnedVectorPluggableAsAcceleration(): void
    {
        $p = Projectile::new(
            deltaTime: 1.0 / 60.0,
            position: new Point(0.0, 0.0),
            velocity: new Vector(0.0, 10.0),
            acceleration: $this->silently(fn(): Vector => Gravity::standard()),
        );
        // After one frame the Y velocity should drop by 9.81 * dt.
        $next = $p->update();
        $this->assertEqualsWithDelta(10.0 - 9.81 / 60.0, $next->velocity->y, 1e-6);
    }

    /**
     * Run a deprecated-surface call under a swallowing handler so the
     * suite's failOnWarning stays clean — the loudness is pinned
     * separately, on purpose, exactly once per accessor.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function silently(callable $call): mixed
    {
        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}

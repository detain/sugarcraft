<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringCollection;
use PHPUnit\Framework\TestCase;

final class SpringCollectionTest extends TestCase
{
    public function testWithSpringAndGet(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('spring1', $spring, 0.0, 0.0, 100.0);

        $this->assertTrue($collection->has('spring1'));
        $this->assertEqualsWithDelta(0.0, $collection->position('spring1'), 1e-9);
    }

    public function testWithSpringReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $original = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $added = $original->withSpring('a', $spring, 0.0, 0.0, 10.0);

        $this->assertNotSame($original, $added);
        $this->assertTrue($added->has('a'));
        $this->assertFalse($original->has('a'), 'withSpring() must not mutate the original');
    }

    public function testWithoutReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $withSpring = (new SpringCollection())->withSpring('a', $spring);

        $without = $withSpring->without('a');

        $this->assertNotSame($withSpring, $without);
        $this->assertFalse($without->has('a'));
        $this->assertTrue($withSpring->has('a'), 'without() must not mutate the original');
    }

    public function testWithTargetReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $original = (new SpringCollection())->withSpring('a', $spring, 0.0, 0.0, 10.0);

        $retargeted = $original->withTarget('a', 50.0);

        $this->assertNotSame($original, $retargeted);
        $this->assertEqualsWithDelta(50.0, $retargeted->target('a'), 1e-9);
        $this->assertEqualsWithDelta(10.0, $original->target('a'), 1e-9, 'withTarget() must not mutate the original');
    }

    public function testTickUpdatesPositions(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('spring1', $spring, 0.0, 0.0, 100.0);

        // First tick should move the spring toward target (immutable - returns new collection).
        $newCollection = $collection->tick();
        $positions = $newCollection->all();
        $this->assertGreaterThan(0.0, $positions['spring1']);
        // Original is untouched by tick().
        $this->assertEqualsWithDelta(0.0, $collection->position('spring1'), 1e-9);
    }

    /**
     * SPR-13 (finding 006): a fully-settled collection ticks to IDENTITY —
     * the per-frame rebuild (and with it the constructor revalidation of
     * finding 004) only happens while something moves.
     */
    public function testSettledCollectionTicksToTheSameInstance(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('a', $spring, 100.0, 0.0, 100.0);

        $this->assertTrue($collection->settled());
        $this->assertSame($collection, $collection->tick(), 'settled tick must be identity');
        $this->assertSame($collection, $collection->tick()->tick(), 'and stay identity across frames');
    }

    /**
     * SPR-13 (finding 006): settled MEMBERS are copied, not re-integrated.
     * A member parked 5e-5 under the settling threshold must keep that exact
     * float; re-integrating it (the old behaviour) multiplies the offset by
     * the decay coefficient and visibly shrinks it.
     */
    public function testSettledMembersKeepTheirFloatsUntouched(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('parked', $spring, 100.00005, 0.0, 100.0) // under 1e-4 threshold: settled
            ->withSpring('running', $spring, 0.0, 0.0, 50.0);

        $ticked = $collection->tick();

        $this->assertSame(100.00005, $ticked->position('parked'), 'settled member must ride through byte-identical');
        $this->assertGreaterThan(0.0, $ticked->position('running'), 'unsettled member still integrates');
    }

    /**
     * SPR-13 (findings 004/006): the two doors DIFFER. The constructor
     * (cold door) fully validates and refuses misaligned planes; tick() (hot
     * door) never re-enters it — pinned at source level in the spirit of the
     * house source-scan tests, so re-wiring tick() through mutate() reddens
     * this instead of silently re-entering full validation per frame.
     */
    public function testHotDoorBypassesTheColdDoorByDesign(): void
    {
        // Cold door still refuses garbage:
        try {
            new SpringCollection(
                ['a' => new Spring(1.0 / 60.0, 6.0, 1.0)],
                ['a' => 0.0],           // velocities/targets missing -> misaligned
                [],
                [],
            );
            $this->fail('constructor must still validate planes');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('is missing', $e->getMessage());
        }

        // Hot door never calls the constructor on the rebuild path:
        $tick = new \ReflectionMethod(SpringCollection::class, 'tick');
        $lines = array_slice(
            file($tick->getFileName()),
            $tick->getStartLine() - 1,
            $tick->getEndLine() - $tick->getStartLine() + 1,
        );
        $body = implode('', $lines);

        $this->assertStringNotContainsString('$this->mutate(', $body, 'tick() must not re-enter the validating rebuild');
        $this->assertStringNotContainsString('new SpringCollection(', $body, 'nor construct through the cold door');
        $this->assertStringContainsString('self::fromParsed(', $body, 'the hot path is the pre-validated rebuild');
    }

    public function testAllReturnsAllPositions(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('a', $spring, 0.0, 0.0, 10.0)
            ->withSpring('b', $spring, 0.0, 0.0, 20.0);

        $all = $collection->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }

    public function testMultipleSpringsConverge(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('fast', $spring, 0.0, 0.0, 100.0)
            ->withSpring('slow', $spring, 0.0, 0.0, 50.0);

        // Run many ticks (immutable - each tick returns a new collection).
        for ($i = 0; $i < 600; $i++) {
            $collection = $collection->tick();
        }

        $all = $collection->all();
        $this->assertEqualsWithDelta(100.0, $all['fast'], 0.01);
        $this->assertEqualsWithDelta(50.0, $all['slow'], 0.01);
    }

    public function testWithTarget(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('spring1', $spring, 0.0, 0.0, 10.0)
            ->withTarget('spring1', 50.0);

        $this->assertEqualsWithDelta(50.0, $collection->target('spring1'), 1e-9);
    }

    public function testHasReturnsFalseForNonexistent(): void
    {
        $collection = new SpringCollection();
        $this->assertFalse($collection->has('nonexistent'));
    }

    // ── M-I (review 1102): the completion contract ──────────────────────────

    public function testSettledFalseWhileAnimatingTrueAfterConvergence(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('a', $spring, 0.0, 0.0, 100.0);

        $this->assertFalse($collection->settled(), 'mid-flight must not report settled');

        $ticks = 0;
        while (!$collection->settled()) {
            $collection = $collection->tick();
            $this->assertLessThan(1200, ++$ticks, 'the settle loop must terminate');
        }

        $this->assertTrue($collection->settled());
        $this->assertEqualsWithDelta(100.0, $collection->position('a'), Spring::SETTLING_THRESHOLD);
        $this->assertLessThan(1e-4, abs($collection->velocity('a')), 'velocity must be within threshold too');
    }

    public function testSettledNeedsVelocityTooPositionOnlyStopIsWrong(): void
    {
        // Under-damped springs pass THROUGH the target with real velocity:
        // a position-only stop would paint "done" while the value rings.
        $spring = new Spring(1.0 / 60.0, 13.416, 0.365); // Wobbly
        $collection = (new SpringCollection())->withSpring('a', $spring, 0.0, 0.0, 100.0);

        // Advance until position happens to sit ON the target mid-ring.
        for ($i = 0; $i < 600; $i++) {
            $collection = $collection->tick();
            if (abs($collection->position('a') - 100.0) < 1e-4 && abs($collection->velocity('a')) > 1.0) {
                $this->assertFalse(
                    $collection->settled(),
                    'at-target position with a live velocity must NOT be settled'
                );

                return;
            }
        }
        $this->markTestSkipped('did not cross the target with a live velocity within 600 frames');
    }

    public function testVelocityAndAllVelocitiesExposeStoredState(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('a', $spring, 0.0, 3.5, 100.0);

        $this->assertSame(3.5, $collection->velocity('a'));
        $this->assertSame(['a' => 3.5], $collection->allVelocities());
    }

    // ── Misuse guards (devops m1 / int m4 / senior m5 / tui m2) ─────────────

    public function testUnknownIdReadsThrowNamingTheId(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('menu', $spring);

        foreach (['position', 'target', 'velocity'] as $method) {
            try {
                $collection->{$method}('menue');
                $this->fail("{$method}() on a typo'd id must throw");
            } catch (\OutOfBoundsException $e) {
                $this->assertStringContainsString('menue', $e->getMessage(), "{$method}() must name the id");
            }
        }

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('menue');
        $collection->withTarget('menue', 10.0);
    }

    public function testConstructorRefusesMisalignedPlanes(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        // A spring with no position entry: the old code surfaced this as an
        // internals-flavoured TypeError inside tick(), frames later.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringCollection is missing positions data for spring "a"');
        new SpringCollection(
            springs: ['a' => $spring],
            positions: [],
            velocities: ['a' => 0.0],
            targets: ['a' => 10.0],
        );
    }

    public function testConstructorRefusesOrphanPlaneEntries(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ghost');
        new SpringCollection(
            springs: ['a' => $spring],
            positions: ['a' => 0.0, 'ghost' => 5.0],
            velocities: ['a' => 0.0],
            targets: ['a' => 10.0],
        );
    }

    public function testConstructorRefusesNonFinitePlaneValues(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a finite number');
        new SpringCollection(
            springs: ['a' => $spring],
            positions: ['a' => NAN],
            velocities: ['a' => 0.0],
            targets: ['a' => 10.0],
        );
    }

    public function testConstructorRefusesNonSpringValuesInSpringsPlane(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a Spring instance');
        new SpringCollection(
            springs: ['a' => new \stdClass()],
            positions: ['a' => 0.0],
            velocities: ['a' => 0.0],
            targets: ['a' => 10.0],
        );
    }

    // ── Deprecated shims (REMOVED-IN: 2.0.0) ────────────────────────────────

    /**
     * SPR-17 (finding 019 ruling): the shims are now copy-on-write — they
     * return the NEW collection and leave the receiver untouched, so the old
     * `$c = $c->add(...)` null-assignment footgun is dead and the plain
     * `$c->add(...)` mutation half is honestly disclosed as gone. The
     * deprecation notices themselves are asserted in the legs below.
     */
    public function testDeprecatedShimsAreCopyOnWrite(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = new SpringCollection();

        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            $added = $collection->add('spring1', $spring, 0.0, 0.0, 10.0);
            $this->assertFalse($collection->has('spring1'), 'add() must NOT mutate the receiver anymore');
            $this->assertTrue($added->has('spring1'), 'add() returns the grown collection');

            $retargeted = $added->setTarget('spring1', 42.0);
            $this->assertEqualsWithDelta(10.0, $added->target('spring1'), 1e-9, 'setTarget() must not touch the receiver');
            $this->assertEqualsWithDelta(42.0, $retargeted->target('spring1'), 1e-9, 'setTarget() returns the retargeted collection');

            $removed = $retargeted->remove('spring1');
            $this->assertTrue($retargeted->has('spring1'), 'remove() must not touch the receiver');
            $this->assertFalse($removed->has('spring1'), 'remove() returns the shrunk collection');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @dataProvider deprecatedCalls
     */
    public function testDeprecatedShimsEmitDeprecation(callable $call, string $replacement): void
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

        $this->assertCount(1, $captured, 'shim must emit exactly one deprecation');
        $this->assertSame(E_USER_DEPRECATED, $captured[0][0]);
        $this->assertStringContainsStringIgnoringCase('deprecated', $captured[0][1]);
        $this->assertStringContainsString($replacement, $captured[0][1]);
        // SPR-17 (finding 054): one expiry vocabulary across the whole lib.
        $this->assertStringContainsString('REMOVED-IN: 2.0.0', $captured[0][1]);
    }

    /** @return iterable<string, array{0: callable, 1: string}> */
    public static function deprecatedCalls(): iterable
    {
        // withSpring() is used for setup because it does not emit a deprecation,
        // so only the shim under test contributes to the captured notice.
        yield 'add' => [
            static fn() => (new SpringCollection())->add('x', new Spring(1.0 / 60.0, 6.0, 1.0)),
            'withSpring()',
        ];
        yield 'remove' => [
            static fn() => (new SpringCollection())
                ->withSpring('x', new Spring(1.0 / 60.0, 6.0, 1.0))
                ->remove('x'),
            'without()',
        ];
        yield 'setTarget' => [
            static fn() => (new SpringCollection())
                ->withSpring('x', new Spring(1.0 / 60.0, 6.0, 1.0))
                ->setTarget('x', 5.0),
            'withTarget()',
        ];
        // SPR-17 (finding 051): the read-side forwarder is loud too — no
        // silent migration lingers on the getter while every writer warns.
        yield 'getTarget' => [
            static fn() => (new SpringCollection())
                ->withSpring('x', new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 5.0)
                ->getTarget('x'),
            'target()',
        ];
    }
}

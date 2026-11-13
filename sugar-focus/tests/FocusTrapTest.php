<?php

declare(strict_types=1);

namespace SugarCraft\Focus\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Focus\FocusRing;
use SugarCraft\Focus\FocusTrap;

final class FocusTrapTest extends TestCase
{
    private static function manual(FocusRing $ring): FocusRing
    {
        $saved = $ring->current();
        $background = $ring->enabledIds();
        $ring = $ring->register('d1')->focus('d1');
        foreach ($background as $id) {
            $ring = $ring->disable($id);
        }
        foreach ($background as $id) {
            $ring = $ring->enable($id);
        }
        $ring = $ring->unregister('d1');

        return $saved === null ? $ring->focusFirst() : $ring->focus($saved);
    }

    public function testLifecycleMatchesHandAssembledRitual(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b');
        $trap = $ring->trap()->guarding('d1');

        self::assertSame('d1', $trap->ring()->current());
        self::assertSame(['d1'], $trap->ring()->enabledIds());
        self::assertSame(['a', 'b', 'c'], $trap->ring()->disabledIds());

        $out = $trap->release();
        self::assertEquals(self::manual($ring), $out);
        self::assertSame('b', $out->current());
        self::assertSame(['a', 'b', 'c'], $out->ids());
        self::assertSame([], $out->disabledIds());
    }

    public function testTabInsideTrapStaysInDialog(): void
    {
        $trap = FocusTrap::new(FocusRing::of('a', 'b'))->guarding('d1', 'd2');
        $trap = $trap->withRing($trap->ring()->next());
        self::assertSame('d2', $trap->ring()->current());
        $trap = $trap->withRing($trap->ring()->next());
        self::assertSame('d1', $trap->ring()->current());
    }

    public function testReleaseParkedAtOpenFallsToFirst(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b')->disable('b');
        $out = $ring->trap()->guarding('d1')->release();
        self::assertSame('a', $out->current());
        self::assertSame(['b'], $out->disabledIds());
    }

    public function testReleaseOnEmptyRingOpen(): void
    {
        $trap = FocusRing::new()->trap();
        self::assertNull($trap->saved());
        $out = $trap->guarding('d1')->release();
        self::assertTrue($out->isEmpty());
    }

    public function testSavedDisabledMidwayFallsToFirstEnabled(): void
    {
        $trap = FocusRing::of('a', 'b', 'c')->focus('c')->trap()->guarding('d1');
        $trap = $trap->withRing($trap->ring()->disable('c'));
        // release re-enables background first, so c is back and restored
        self::assertSame('c', $trap->release()->current());
    }

    public function testSavedUnregisteredMidwayFallsToFirst(): void
    {
        $trap = FocusRing::of('a', 'b', 'c')->focus('c')->trap()->guarding('d1');
        $trap = $trap->withRing($trap->ring()->unregister('c'));
        self::assertSame('a', $trap->release()->current());
    }

    public function testImmutabilityAndAccessors(): void
    {
        $ring = FocusRing::of('a', 'b');
        $trap = FocusTrap::new($ring);
        $guarded = $trap->guarding('d1');

        self::assertSame([], $trap->dialog());
        self::assertSame(['d1'], $guarded->dialog());
        self::assertSame(['a', 'b'], $guarded->background());
        self::assertSame('a', $guarded->saved());
        self::assertSame(['a', 'b'], $ring->enabledIds(), 'base ring untouched');
        self::assertNotSame($trap, $guarded);
    }

    public function testGuardingRejectsAlreadyRegisteredId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FocusRing::of('a')->trap()->guarding('a');
    }

    public function testCachesConsistentOnBothLegs(): void
    {
        $trap = FocusRing::of('a', 'b', 'c')->focus('b')->trap()->guarding('d1', 'd2');
        $this->assertCache($trap->ring());
        $this->assertCache($trap->release());
    }

    private function assertCache(FocusRing $ring): void
    {
        $ref = new \ReflectionProperty(FocusRing::class, 'enabledPositions');
        $disabled = array_fill_keys($ring->disabledIds(), true);
        $expected = [];
        foreach ($ring->ids() as $i => $id) {
            if (!isset($disabled[$id])) {
                $expected[] = $i;
            }
        }
        self::assertSame($expected, $ref->getValue($ring));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{Position, Veil};
use PHPUnit\Framework\TestCase;

/**
 * M5 (docs-fix-1023): the RenderSession is mutable and SHARED across
 * with*() clones by design — with*() changes appearance, not render state.
 * Two render paths derived from one veil interleave into one delta chain.
 * The README now says so loudly; these pins keep the design honest:
 * clones share, withoutSession() separates, and resetPreviousFrame()
 * invalidates every clone at once.
 */
final class SessionSharedAcrossClonesTest extends TestCase
{
    private const BG = "AAAA\nAAAA";

    private static function sessionOf(Veil $veil): object
    {
        $property = (new \ReflectionClass(Veil::class))->getProperty('session');

        return $property->getValue($veil);
    }

    public function testWithClonesCarryTheSameSessionInstance(): void
    {
        $base  = Veil::new();
        $clone = $base
            ->withBackdrop(50)
            ->withZIndex(7)
            ->withContent('[panel]');

        $this->assertSame(self::sessionOf($base), self::sessionOf($clone));
    }

    public function testWithoutSessionIsTheDocumentedIsolationSeam(): void
    {
        $base   = Veil::new();
        $detached = $base->withoutSession();

        $this->assertNotSame(self::sessionOf($base), self::sessionOf($detached));
    }

    public function testCompositingThroughACloneAdvancesTheOriginalsDiffMemory(): void
    {
        $base  = Veil::new();
        $clone = $base->withZIndex(7); // appearance-only: zIndex is inert for a lone composite

        $full = $base->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $this->assertNotSame('', $full);

        // The clone paints the same state on the SHARED session: the
        // original already remembered this frame, so nothing new ships.
        $this->assertSame('', $clone->composite("Hi", self::BG, Position::TOP, Position::LEFT));
    }

    public function testResetPreviousFrameInvalidatesEveryClone(): void
    {
        $base  = Veil::new();
        $clone = $base->withZIndex(3);

        $full = $base->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $this->assertSame('', $base->composite("Hi", self::BG, Position::TOP, Position::LEFT));

        $clone->resetPreviousFrame();

        $this->assertSame($full, $base->composite("Hi", self::BG, Position::TOP, Position::LEFT),
            'reset mutates through the shared session: it invalidates for every clone');
    }
}

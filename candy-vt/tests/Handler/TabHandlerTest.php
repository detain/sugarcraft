<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Handler\TabHandler;

final class TabHandlerTest extends TestCase
{
    // ─── Defaults ──────────────────────────────────────────────────────────

    public function testDefaultsAreEvery8ColsStartingAt8(): void
    {
        $stops = TabHandler::defaults(40);
        $this->assertSame([8 => true, 16 => true, 24 => true, 32 => true], $stops);
    }

    public function testDefaultsForNarrowBufferYieldsNoStops(): void
    {
        $stops = TabHandler::defaults(7);
        $this->assertSame([], $stops);
    }

    // ─── Forward / backward ────────────────────────────────────────────────

    public function testForwardFindsNextStop(): void
    {
        $h = new TabHandler();
        $stops = [8 => true, 16 => true];
        $this->assertSame(8, $h->forward(0, $stops, 80));
        $this->assertSame(8, $h->forward(7, $stops, 80));
        $this->assertSame(16, $h->forward(8, $stops, 80));
    }

    public function testForwardClampsAtRightEdgeWhenNoMoreStops(): void
    {
        $h = new TabHandler();
        $stops = [8 => true];
        $this->assertSame(19, $h->forward(8, $stops, 20));
        $this->assertSame(19, $h->forward(15, $stops, 20));
    }

    public function testBackwardFindsPreviousStop(): void
    {
        $h = new TabHandler();
        $stops = [4 => true, 12 => true];
        $this->assertSame(12, $h->backward(15, $stops));
        $this->assertSame(4, $h->backward(12, $stops));
        $this->assertSame(0, $h->backward(4, $stops));
    }

    public function testBackwardClampsAtZeroWhenNoStops(): void
    {
        $h = new TabHandler();
        $this->assertSame(0, $h->backward(20, []));
    }

    // ─── CHT (CSI I) / CBT (CSI Z) ─────────────────────────────────────────

    public function testApplyCsiIMovesForwardNTimes(): void
    {
        $h = new TabHandler();
        $stops = [8 => true, 16 => true, 24 => true];
        $this->assertSame(24, $h->applyCsi(ord('I'), [3], 0, $stops, 80));
    }

    public function testApplyCsiIDefaultParamMovesByOne(): void
    {
        $h = new TabHandler();
        $stops = [8 => true, 16 => true];
        $this->assertSame(8, $h->applyCsi(ord('I'), [], 0, $stops, 80));
    }

    public function testApplyCsiZMovesBackwardNTimes(): void
    {
        $h = new TabHandler();
        $stops = [4 => true, 12 => true, 20 => true];
        // From col 25: back to 20, then back to 12.
        $this->assertSame(12, $h->applyCsi(ord('Z'), [2], 25, $stops, 80));
    }

    public function testApplyCsiUnknownFinalReturnsCurrentCol(): void
    {
        $h = new TabHandler();
        $this->assertSame(5, $h->applyCsi(ord('X'), [], 5, [8 => true], 80));
    }

    // ─── TBC ───────────────────────────────────────────────────────────────

    public function testClearMode0RemovesStopAtCol(): void
    {
        $h = new TabHandler();
        $stops = [4 => true, 8 => true];
        $this->assertSame([4 => true], $h->clear(0, 8, $stops));
    }

    public function testClearMode0ColWithoutStopIsNoOp(): void
    {
        $h = new TabHandler();
        $stops = [8 => true];
        $this->assertSame([8 => true], $h->clear(0, 5, $stops));
    }

    public function testClearMode3RemovesAllStops(): void
    {
        $h = new TabHandler();
        $this->assertSame([], $h->clear(3, 4, [4 => true, 8 => true]));
    }

    public function testClearUnknownModeIsNoOp(): void
    {
        $h = new TabHandler();
        $stops = [8 => true];
        $this->assertSame($stops, $h->clear(7, 8, $stops));
    }
}

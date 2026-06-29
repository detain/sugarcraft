<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Output;

use SugarCraft\Pty\Output\SgrHandler;
use SugarCraft\Pty\Output\SgrState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SgrHandler malformed-input remediation (Step 1).
 *
 * Covers:
 * - 256-color indices outside 0-255 are clamped (Step 1)
 * - Orphaned "5" / "2" markers after CSI 38/48 are consumed (Step 1)
 * - drainTransitions() returns recorded events and clears the log
 */
final class SgrHandlerMalformedTest extends TestCase
{
    public function testForeground256Index500IsClampedTo255(): void
    {
        $h = new SgrHandler();
        // CSI 38;5;500m — index 500 is out of range
        $h->csiDispatch(0x6D, [38, 5, 500], 0, 0);
        $this->assertSame(255, $h->state->foreground256);
    }

    public function testForeground256IndexNegative5IsClampedTo0(): void
    {
        $h = new SgrHandler();
        // CSI 38;5;-5m — negative index
        $h->csiDispatch(0x6D, [38, 5, -5], 0, 0);
        $this->assertSame(0, $h->state->foreground256);
    }

    public function testBackground256Index300IsClampedTo255(): void
    {
        $h = new SgrHandler();
        // CSI 48;5;300m
        $h->csiDispatch(0x6D, [48, 5, 300], 0, 0);
        $this->assertSame(255, $h->state->background256);
    }

    public function testOrphaned5MarkerAfter38IsConsumed(): void
    {
        $h = new SgrHandler();
        // CSI 38;5m — 38 with orphaned "5" and no index following
        // Should not raise, and the orphaned 5 should NOT be re-processed
        // as a standalone SGR code.
        $h->csiDispatch(0x6D, [38, 5], 0, 0);
        // State should remain at default (no foreground color set)
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame(SgrState::COLOR_256, $h->state->foreground256);
    }

    public function testOrphaned2MarkerAfter38IsConsumed(): void
    {
        $h = new SgrHandler();
        // CSI 38;2m — 38 with orphaned "2" and no RGB components following
        $h->csiDispatch(0x6D, [38, 2], 0, 0);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame(SgrState::COLOR_256, $h->state->foreground256);
    }

    public function testOrphaned5MarkerAfter48IsConsumed(): void
    {
        $h = new SgrHandler();
        // CSI 48;5m — orphaned "5"
        $h->csiDispatch(0x6D, [48, 5], 0, 0);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
        $this->assertSame(SgrState::COLOR_256, $h->state->background256);
    }

    public function testDrainTransitionsReturnsEventsAndClearsLog(): void
    {
        $h = new SgrHandler();
        // Trigger one transition: set foreground to red (code 31)
        $h->csiDispatch(0x6D, [31], 0, 0);

        $transitions = $h->drainTransitions();
        $this->assertCount(1, $transitions);

        [$from, $to] = $transitions[0];
        $this->assertSame(SgrState::COLOR_DEFAULT, $from->foreground);
        $this->assertSame(1, $to->foreground); // COLOR_RED

        // Second drain should return empty array
        $this->assertSame([], $h->drainTransitions());
    }

    public function testDrainTransitionsRecordsMultipleTransitions(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [1], 0, 0);   // bold
        $h->csiDispatch(0x6D, [31], 0, 0);  // red foreground

        $transitions = $h->drainTransitions();
        $this->assertCount(2, $transitions);

        $this->assertTrue($transitions[0][1]->bold);
        $this->assertSame(1, $transitions[1][1]->foreground);
    }
}

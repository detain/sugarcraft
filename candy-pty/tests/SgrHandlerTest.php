<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use SugarCraft\Pty\Output\SgrHandler;
use SugarCraft\Pty\Output\SgrState;
use PHPUnit\Framework\TestCase;

final class SgrHandlerTest extends TestCase
{
    public function testDefaultConstructorCreatesDefaultState(): void
    {
        $h = new SgrHandler();
        $this->assertSame('default', $h->state->describe());
    }

    public function testConstructorWithInitialState(): void
    {
        $initial = new SgrState(foreground: SgrState::COLOR_RED, bold: true);
        $h = new SgrHandler($initial);
        $this->assertSame('bold fg=red', $h->state->describe());
    }

    public function testPrintCharIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->printChar('x');
        $this->assertSame('default', $h->state->describe());
    }

    public function testExecuteIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->execute(0x07);
        $this->assertSame('default', $h->state->describe());
    }

    public function testEscDispatchIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->escDispatch(0x5A, 0);
        $this->assertSame('default', $h->state->describe());
    }

    public function testOscDispatchIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->oscDispatch('2;foo');
        $this->assertSame('default', $h->state->describe());
    }

    public function testDcsDispatchIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->dcsDispatch(0x50, [], 0, 0, '');
        $this->assertSame('default', $h->state->describe());
    }

    public function testSosPmApcDispatchIsNoOp(): void
    {
        $h = new SgrHandler();
        $h->sosPmApcDispatch('X', 'data');
        $this->assertSame('default', $h->state->describe());
    }

    public function testCsiDispatchIgnoresNonSgrSequences(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x41, [], 0, 0, []);
        $this->assertSame('default', $h->state->describe());
    }

    public function testSgr0ResetsAllAttributes(): void
    {
        $h = new SgrHandler(new SgrState(bold: true, italic: true, foreground: SgrState::COLOR_RED));
        $h->csiDispatch(0x6D, [0], 0, 0, []);
        $this->assertSame('default', $h->state->describe());
    }

    public function testSgr0WithEmptyParamsResetsAttributes(): void
    {
        $h = new SgrHandler(new SgrState(bold: true));
        $h->csiDispatch(0x6D, [], 0, 0, []);
        $this->assertSame('default', $h->state->describe());
    }

    public function testSgr0WithNegativeOneResetsAttributes(): void
    {
        $h = new SgrHandler(new SgrState(bold: true));
        $h->csiDispatch(0x6D, [-1], 0, 0, []);
        $this->assertSame('default', $h->state->describe());
    }

    public function testSgr1Bold(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [1], 0, 0, []);
        $this->assertTrue($h->state->bold);
    }

    public function testSgr2Dim(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [2], 0, 0, []);
        $this->assertTrue($h->state->dim);
    }

    public function testSgr3Italic(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [3], 0, 0, []);
        $this->assertTrue($h->state->italic);
    }

    public function testSgr4Underline(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [4], 0, 0, []);
        $this->assertTrue($h->state->underline);
    }

    public function testSgr5BlinkSlow(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [5], 0, 0, []);
        $this->assertTrue($h->state->blink);
    }

    public function testSgr6BlinkRapid(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [6], 0, 0, []);
        $this->assertTrue($h->state->blink);
    }

    public function testSgr7Reverse(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [7], 0, 0, []);
        $this->assertTrue($h->state->reverse);
    }

    public function testSgr8Invisible(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [8], 0, 0, []);
        $this->assertTrue($h->state->invisible);
    }

    public function testSgr9Strike(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [9], 0, 0, []);
        $this->assertTrue($h->state->strike);
    }

    public function testSgr21TurnsOffBoldAndDim(): void
    {
        $h = new SgrHandler(new SgrState(bold: true, dim: true));
        $h->csiDispatch(0x6D, [21], 0, 0, []);
        $this->assertFalse($h->state->bold);
        $this->assertFalse($h->state->dim);
    }

    public function testSgr22TurnsOffBoldAndDim(): void
    {
        $h = new SgrHandler(new SgrState(bold: true, dim: true));
        $h->csiDispatch(0x6D, [22], 0, 0, []);
        $this->assertFalse($h->state->bold);
        $this->assertFalse($h->state->dim);
    }

    public function testSgr23TurnsOffItalic(): void
    {
        $h = new SgrHandler(new SgrState(italic: true));
        $h->csiDispatch(0x6D, [23], 0, 0, []);
        $this->assertFalse($h->state->italic);
    }

    public function testSgr24TurnsOffUnderline(): void
    {
        $h = new SgrHandler(new SgrState(underline: true));
        $h->csiDispatch(0x6D, [24], 0, 0, []);
        $this->assertFalse($h->state->underline);
    }

    public function testSgr25TurnsOffBlink(): void
    {
        $h = new SgrHandler(new SgrState(blink: true));
        $h->csiDispatch(0x6D, [25], 0, 0, []);
        $this->assertFalse($h->state->blink);
    }

    public function testSgr27TurnsOffReverse(): void
    {
        $h = new SgrHandler(new SgrState(reverse: true));
        $h->csiDispatch(0x6D, [27], 0, 0, []);
        $this->assertFalse($h->state->reverse);
    }

    public function testSgr28TurnsOffInvisible(): void
    {
        $h = new SgrHandler(new SgrState(invisible: true));
        $h->csiDispatch(0x6D, [28], 0, 0, []);
        $this->assertFalse($h->state->invisible);
    }

    public function testSgr29TurnsOffStrike(): void
    {
        $h = new SgrHandler(new SgrState(strike: true));
        $h->csiDispatch(0x6D, [29], 0, 0, []);
        $this->assertFalse($h->state->strike);
    }

    public function testStandardForegroundColors30to37(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [30], 0, 0, []);
        $this->assertSame(0, $h->state->foreground);
        $h->csiDispatch(0x6D, [31], 0, 0, []);
        $this->assertSame(1, $h->state->foreground);
        $h->csiDispatch(0x6D, [32], 0, 0, []);
        $this->assertSame(2, $h->state->foreground);
        $h->csiDispatch(0x6D, [33], 0, 0, []);
        $this->assertSame(3, $h->state->foreground);
        $h->csiDispatch(0x6D, [34], 0, 0, []);
        $this->assertSame(4, $h->state->foreground);
        $h->csiDispatch(0x6D, [35], 0, 0, []);
        $this->assertSame(5, $h->state->foreground);
        $h->csiDispatch(0x6D, [36], 0, 0, []);
        $this->assertSame(6, $h->state->foreground);
        $h->csiDispatch(0x6D, [37], 0, 0, []);
        $this->assertSame(7, $h->state->foreground);
    }

    public function testStandardBackgroundColors40to47(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [40], 0, 0, []);
        $this->assertSame(0, $h->state->background);
        $h->csiDispatch(0x6D, [41], 0, 0, []);
        $this->assertSame(1, $h->state->background);
        $h->csiDispatch(0x6D, [47], 0, 0, []);
        $this->assertSame(7, $h->state->background);
    }

    public function testBrightForegroundColors90to97(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [90], 0, 0, []);
        $this->assertSame(8, $h->state->foreground);
        $h->csiDispatch(0x6D, [91], 0, 0, []);
        $this->assertSame(9, $h->state->foreground);
        $h->csiDispatch(0x6D, [97], 0, 0, []);
        $this->assertSame(15, $h->state->foreground);
    }

    public function testBrightBackgroundColors100to107(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [100], 0, 0, []);
        $this->assertSame(8, $h->state->background);
        $h->csiDispatch(0x6D, [107], 0, 0, []);
        $this->assertSame(15, $h->state->background);
    }

    /**
     * F1 (lane A7 re-verify): with COLOR_DEFAULT colliding with palette slot 9,
     * ESC[31m ESC[91m ESC[39m reported only 2 of its 3 transitions. Each step
     * must now surface in the event log.
     */
    public function testBrightAfterBaseColorRecordsEveryTransition(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [31], 0, 0, []);  // default → red
        $h->csiDispatch(0x6D, [91], 0, 0, []);  // red → bright red (was swallowed)
        $h->csiDispatch(0x6D, [39], 0, 0, []);  // bright red → default

        $events = $h->drainTransitions();
        $this->assertCount(3, $events);

        [$from1, $to1] = $events[0];
        $this->assertSame(SgrState::COLOR_DEFAULT, $from1->foreground);
        $this->assertSame(SgrState::COLOR_RED, $to1->foreground);

        [$from2, $to2] = $events[1];
        $this->assertSame(SgrState::COLOR_RED, $from2->foreground);
        $this->assertSame(SgrState::COLOR_BRIGHT_RED, $to2->foreground);

        [$from3, $to3] = $events[2];
        $this->assertSame(SgrState::COLOR_BRIGHT_RED, $from3->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $to3->foreground);
    }

    /**
     * F1: every bright fg alias lands on its xterm palette slot 8-15, is
     * distinguishable from the default sentinel, and renders by name.
     */
    public function testEveryBrightForegroundIsDistinctFromDefault(): void
    {
        $brights = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'];
        foreach ($brights as $offset => $name) {
            $h = new SgrHandler();
            $h->csiDispatch(0x6D, [31], 0, 0, []);          // leave default first
            $h->csiDispatch(0x6D, [90 + $offset], 0, 0, []); // → bright variant
            $this->assertSame(8 + $offset, $h->state->foreground);
            $this->assertNotSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
            $this->assertStringContainsString("fg=bright-{$name}", $h->state->describe());
            $this->assertFalse($h->state->equals(new SgrState()), "SGR " . (90 + $offset) . ' must not equal default state');
        }
    }

    /** F1: same contract for the bright background aliases 100-107. */
    public function testEveryBrightBackgroundIsDistinctFromDefault(): void
    {
        $brights = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'];
        foreach ($brights as $offset => $name) {
            $h = new SgrHandler();
            $h->csiDispatch(0x6D, [41], 0, 0, []);            // leave default first
            $h->csiDispatch(0x6D, [100 + $offset], 0, 0, []); // → bright variant
            $this->assertSame(8 + $offset, $h->state->background);
            $this->assertNotSame(SgrState::COLOR_DEFAULT, $h->state->background);
            $this->assertStringContainsString("bg=bright-{$name}", $h->state->describe());
            $this->assertFalse($h->state->equals(new SgrState()), 'SGR ' . (100 + $offset) . ' must not equal default state');
        }
    }

    /** F1: SGR 39 from a bright color returns to the default sentinel. */
    public function testDefaultResetFromBrightReturnsToDefaultSentinel(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [91], 0, 0, []);
        $h->csiDispatch(0x6D, [39], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame('default', $h->state->describe());

        $h2 = new SgrHandler();
        $h2->csiDispatch(0x6D, [107], 0, 0, []);
        $h2->csiDispatch(0x6D, [49], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h2->state->background);
        $this->assertSame('default', $h2->state->describe());
    }

    /**
     * F2 (lane A7 re-verify): feeding chunks without draining must not grow
     * the transition log without bound. The log is drop-OLDEST: the newest
     * transitions always survive, the head of history is what gets evicted.
     */
    public function testUndrainedTransitionLogIsBoundedDropOldest(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [31], 0, 0, []); // E1: default → red (oldest)

        // Churn twice the cap through red ↔ green, never draining.
        for ($i = 0; $i < 2 * SgrHandler::MAX_EVENTS; $i++) {
            $h->csiDispatch(0x6D, [$i % 2 === 0 ? 32 : 31], 0, 0, []);
        }
        $h->csiDispatch(0x6D, [0], 0, 0, []); // final: → default (newest)

        $events = $h->drainTransitions();
        $this->assertCount(SgrHandler::MAX_EVENTS, $events);

        // Drop-OLDEST, not drop-newest: the evicted head was E1 (default→red),
        // so no surviving pair starts from the default color…
        foreach ($events as [$from, $_to]) {
            $this->assertNotSame(SgrState::COLOR_DEFAULT, $from->foreground, 'oldest events must be the ones evicted');
        }
        // …and the newest event (the explicit reset) is intact at the tail.
        [$fromLast, $toLast] = $events[\count($events) - 1];
        $this->assertNotSame(SgrState::COLOR_DEFAULT, $fromLast->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $toLast->foreground);
    }

    /**
     * F5 (lane A7 re-verify): truecolor sub-params clamp to 0-255 at parse
     * time like the 38;5 path, so a wild channel cannot wrap bits into its
     * neighbour (300 read as 44 before the fix).
     */
    public function testTruecolorSubParamsAreClampedToBytes(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 300, -5, 128], 0, 0, []);
        $this->assertSame((255 << 16) | (0 << 8) | 128, $h->state->foregroundRgb);
        $this->assertSame('fg=rgb(255,0,128)', $h->state->describe());

        // Clamped channels stay positionally distinguishable.
        $hr = new SgrHandler();
        $hr->csiDispatch(0x6D, [48, 2, 300, 0, 0], 0, 0, []);
        $hg = new SgrHandler();
        $hg->csiDispatch(0x6D, [48, 2, 0, 300, 0], 0, 0, []);
        $this->assertSame(255 << 16, $hr->state->backgroundRgb);
        $this->assertSame(255 << 8, $hg->state->backgroundRgb);
        $this->assertNotSame($hr->state->backgroundRgb, $hg->state->backgroundRgb);
    }

    /** F4 (lane A7 re-verify): SgrHandler::reset clears state and event log. */
    public function testResetClearsStateAndEventLog(): void
    {
        $h = new SgrHandler(new SgrState(foreground: SgrState::COLOR_RED, bold: true));
        $h->csiDispatch(0x6D, [0], 0, 0, []); // produces one transition
        $this->assertNotSame([], $h->drainTransitions()); // sanity: log was live

        $h2 = new SgrHandler(new SgrState(foreground: SgrState::COLOR_RED));
        $h2->reset();
        $this->assertSame('default', $h2->state->describe());
        $this->assertSame([], $h2->drainTransitions());
    }

    public function testSgr38Foreground256Color(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 5, 196], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame(196, $h->state->foreground256);
        $this->assertSame(0, $h->state->foregroundRgb);
    }

    public function testSgr38ForegroundRgbColor(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 255, 128, 0], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame(SgrState::COLOR_RGB, $h->state->foreground256);
        $this->assertSame((255 << 16) | (128 << 8) | 0, $h->state->foregroundRgb);
    }

    public function testSgr48Background256Color(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [48, 5, 21], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
        $this->assertSame(21, $h->state->background256);
    }

    public function testSgr48BackgroundRgbColor(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [48, 2, 0, 255, 128], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
        $this->assertSame(SgrState::COLOR_RGB, $h->state->background256);
        $this->assertSame((0 << 16) | (255 << 8) | 128, $h->state->backgroundRgb);
    }

    public function testSgr39DefaultForeground(): void
    {
        $h = new SgrHandler(new SgrState(foreground: SgrState::COLOR_RED));
        $h->csiDispatch(0x6D, [39], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
    }

    public function testSgr49DefaultBackground(): void
    {
        $h = new SgrHandler(new SgrState(background: SgrState::COLOR_BLUE));
        $h->csiDispatch(0x6D, [49], 0, 0, []);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
    }

    public function testCombinedSgrSequence(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [1, 31, 44], 0, 0, []);
        $this->assertTrue($h->state->bold);
        $this->assertSame(1, $h->state->foreground);
        $this->assertSame(4, $h->state->background);
    }

    public function testMultipleCsiDispatchesAccumulate(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [31], 0, 0, []);
        $this->assertSame(1, $h->state->foreground);
        $h->csiDispatch(0x6D, [1], 0, 0, []);
        $this->assertTrue($h->state->bold);
        $this->assertSame(1, $h->state->foreground);
    }

    public function testResetAfterColorChanges(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [31, 1], 0, 0, []);
        $this->assertSame(1, $h->state->foreground);
        $this->assertTrue($h->state->bold);
        $h->csiDispatch(0x6D, [0], 0, 0, []);
        $this->assertFalse($h->state->bold);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
    }
}

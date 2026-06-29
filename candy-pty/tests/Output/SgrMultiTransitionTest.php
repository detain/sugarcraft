<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Output;

use SugarCraft\Pty\Output\SgrHandler;
use SugarCraft\Pty\Output\SgrState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SgrHandler RGB / multi-transition remediation (Step 3).
 *
 * Verifies:
 * - 24-bit RGB color sets foreground256/background256 = COLOR_RGB (not COLOR_256)
 * - drainTransitions correctly records state-change pairs
 * - Multiple SGR params in one sequence are all processed
 */
final class SgrMultiTransitionTest extends TestCase
{
    public function testRgbForegroundSetsForeground256ToColorRgb(): void
    {
        $h = new SgrHandler();
        // CSI 38;2;255;128;0m — RGB foreground (r=255, g=128, b=0)
        $h->csiDispatch(0x6D, [38, 2, 255, 128, 0], 0, 0);

        $this->assertSame(SgrState::COLOR_RGB, $h->state->foreground256);
        $this->assertSame((255 << 16) | (128 << 8) | 0, $h->state->foregroundRgb);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
    }

    public function testRgbBackgroundSetsBackground256ToColorRgb(): void
    {
        $h = new SgrHandler();
        // CSI 48;2;0;255;128m — RGB background (r=0, g=255, b=128)
        $h->csiDispatch(0x6D, [48, 2, 0, 255, 128], 0, 0);

        $this->assertSame(SgrState::COLOR_RGB, $h->state->background256);
        $this->assertSame((0 << 16) | (255 << 8) | 128, $h->state->backgroundRgb);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
    }

    public function testRgbForegroundTransitionRecorded(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 255, 0, 0], 0, 0); // red RGB

        $transitions = $h->drainTransitions();
        $this->assertCount(1, $transitions);

        [$from, $to] = $transitions[0];
        $this->assertSame(SgrState::COLOR_256, $from->foreground256);
        $this->assertSame(SgrState::COLOR_RGB, $to->foreground256);
    }

    public function testMultipleParamsInOneSequence(): void
    {
        $h = new SgrHandler();
        // CSI 1;31;46m — bold + red fg + cyan bg
        $h->csiDispatch(0x6D, [1, 31, 46], 0, 0);

        $this->assertTrue($h->state->bold);
        $this->assertSame(1, $h->state->foreground);   // COLOR_RED
        $this->assertSame(6, $h->state->background);   // COLOR_CYAN
    }

    public function testRgbFollowedByBoldTransitionRecorded(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 0, 0, 255], 0, 0); // blue RGB
        $h->csiDispatch(0x6D, [1], 0, 0);                 // bold

        $transitions = $h->drainTransitions();
        $this->assertCount(2, $transitions);

        // First: default → blue RGB
        $this->assertSame(SgrState::COLOR_RGB, $transitions[0][1]->foreground256);
        // Second: blue RGB → blue RGB + bold
        $this->assertTrue($transitions[1][1]->bold);
        $this->assertSame(SgrState::COLOR_RGB, $transitions[1][1]->foreground256);
    }

    public function testSgr0ResetsToDefault(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 255, 0, 0], 0, 0); // RGB red
        $h->csiDispatch(0x6D, [1], 0, 0);                 // bold
        $h->csiDispatch(0x6D, [0], 0, 0);                 // reset all

        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $h->state->background);
        $this->assertFalse($h->state->bold);
        $this->assertSame(SgrState::COLOR_256, $h->state->foreground256);
    }
}

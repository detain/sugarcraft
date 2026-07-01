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

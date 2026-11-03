<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Output\SgrHandler;
use SugarCraft\Pty\Output\SgrState;

/**
 * Diagnostic-label pins: describe() dispatches on the MODE sentinel
 * (foreground256/background256 carrying COLOR_RGB), never on payload
 * equality. An SGR 38;2;0;0;0 payload is legitimate BLACK — the retired
 * "rgb !== 0" fast path described exactly that color as "default",
 * erasing the one RGB value a terminal most often paints.
 */
final class SgrStateDescribeSentinelTest extends TestCase
{
    public function testPureBlackRgbIsDescribedNotDefaulted(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 0, 0, 0], 0, 0);

        self::assertStringContainsString('fg=rgb(0,0,0)', $h->state->describe());
    }

    public function testPureBlackRgbBackgroundIsDescribedNotDefaulted(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [48, 2, 0, 0, 0], 0, 0);

        self::assertStringContainsString('bg=rgb(0,0,0)', $h->state->describe());
    }

    public function testNonZeroRgbStillDescribesItsChannels(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 255, 128, 0], 0, 0);

        self::assertStringContainsString('fg=rgb(255,128,0)', $h->state->describe());
    }

    public function testExtendedBlackIndexIsNamedByNumber(): void
    {
        // 38;5;0 — the 256-mode payload 0 is palette black and must read
        // as an explicit index, not fall through to the basic/default arms.
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 5, 0], 0, 0);

        self::assertStringContainsString('fg=0', $h->state->describe());
    }

    public function testResetAfterRgbDescribesDefault(): void
    {
        $h = new SgrHandler();
        $h->csiDispatch(0x6D, [38, 2, 0, 0, 0], 0, 0);
        self::assertStringContainsString('fg=rgb(0,0,0)', $h->state->describe());

        // SGR 39 = default foreground: the rgb payload must be cleared
        // alongside the mode sentinel or black would never go away.
        $h->csiDispatch(0x6D, [39], 0, 0);
        self::assertSame('default', $h->state->describe());
        self::assertSame(0, $h->state->foregroundRgb);
        self::assertSame(SgrState::COLOR_256, $h->state->foreground256);
    }
}

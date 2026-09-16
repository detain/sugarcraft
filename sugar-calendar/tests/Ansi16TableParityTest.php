<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Calendar\DatePicker;
use SugarCraft\Core\Util\Color as CoreColor;

/**
 * Tripwire pinning sugar-calendar's SGR→RGB decode (DatePicker::ansiColorToRgb())
 * to candy-core's canonical xterm ANSI-16 table.
 *
 * Historically the method carried its own private 8-entry table plus a
 * `+96 per channel` bright formula, which forked slot 4 to #000080 (the
 * charmbracelet/x/ansi blue, not the canon) and slot 12 to #6060E0 — the
 * latter matching neither the canon (blue2 #0000EE / rgb:5c/5c/ff #5C5CFF,
 * xterm main.h DEF_COLOR4/DEF_COLOR12) nor any upstream surveyed. The
 * method now indexes
 * {@see CoreColor::ANSI16_RGB} directly; these assertions cover all 16
 * slots so no future slot can silently drift again. Mirrors
 * candy-palette/tests/Ansi16TableParityTest.php.
 */
final class Ansi16TableParityTest extends TestCase
{
    /**
     * Resolve the private decoder once; it has no instance state to feed it.
     */
    private function decode(): ReflectionMethod
    {
        $method = new ReflectionMethod(DatePicker::class, 'ansiColorToRgb');
        $method->setAccessible(true);

        return $method;
    }

    /**
     * Every one of the 16 slots must decode to the canonical triple.
     */
    public function testDecodeIsElementWiseEqualToCore(): void
    {
        $method = $this->decode();
        $picker = DatePicker::new();

        foreach (CoreColor::ANSI16_RGB as $slot => [$r, $g, $b]) {
            $packed = $method->invoke($picker, $slot % 8, $slot >= 8);
            self::assertSame(
                [$r, $g, $b],
                [($packed >> 16) & 0xff, ($packed >> 8) & 0xff, $packed & 0xff],
                "slot {$slot} diverged from candy-core Color::ANSI16_RGB",
            );
        }
    }

    /**
     * The two blues that motivated the unification, pinned to the canonical
     * xterm-411 values so a revert of the table is caught on its own.
     */
    public function testBlueSlotsMatchCanonicalXtermValues(): void
    {
        $method = $this->decode();
        $picker = DatePicker::new();

        self::assertSame(
            0x0000ee,
            $method->invoke($picker, 4, false),
            'SGR 34 must decode to blue2 #0000EE (xterm DEF_COLOR4)',
        );
        self::assertSame(
            0x5c5cff,
            $method->invoke($picker, 4, true),
            'SGR 94 must decode to rgb:5c/5c/ff #5C5CFF (xterm DEF_COLOR12)',
        );
    }
}

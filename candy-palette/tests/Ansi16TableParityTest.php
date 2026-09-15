<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\StandardColors;

/**
 * Tripwire pinning candy-palette's ANSI-16 entry table to candy-core's
 * canonical xterm-modern table.
 *
 * Both libraries publish the same 16-slot RGB table and both feed it to a
 * nearest-by-squared-RGB quantiser (palette {@see Color::toAnsi16Index()},
 * core Color::nearestAnsi16()). Historically they drifted — palette shipped
 * xterm's abandoned pre-2009 blues (#0000CD / #0000FF) while core moved to
 * xterm 411's compiled-in defaults (blue2 #0000EE / rgb:5c/5c/ff #5C5CFF).
 * The split was behaviourally real: `#1e90ff` (dodgerblue) quantised to CYAN
 * (slot 6) in palette but bright blue (slot 12) in core.
 *
 * These assertions make any future silent divergence a test failure rather
 * than a research project. See {@see \SugarCraft\Core\Util\Color::ANSI16_RGB}.
 */
final class Ansi16TableParityTest extends TestCase
{
    /**
     * Every one of the 16 slots must be byte-identical to candy-core's table.
     */
    public function testTableIsElementWiseEqualToCore(): void
    {
        $palette = Color::ANSI16_RGB;
        $core = \SugarCraft\Core\Util\Color::ANSI16_RGB;

        self::assertCount(16, $palette, 'palette table must stay 16 slots');
        self::assertSame(
            \array_keys($core),
            \array_keys($palette),
            'palette table slot keys must match core exactly',
        );
        foreach ($core as $slot => [$r, $g, $b]) {
            self::assertSame(
                [$r, $g, $b],
                $palette[$slot],
                "slot {$slot} diverged from candy-core",
            );
        }
    }

    /**
     * The two blues that motivated the unification, pinned to the canonical
     * xterm-411 values so a revert of the table is caught on its own.
     */
    public function testBlueSlotsMatchCanonicalXtermValues(): void
    {
        self::assertSame([0x00, 0x00, 0xee], Color::ANSI16_RGB[4], 'slot 4 = blue2 #0000EE');
        self::assertSame([0x5c, 0x5c, 0xff], Color::ANSI16_RGB[12], 'slot 12 = rgb:5c/5c/ff #5C5CFF');
    }

    /**
     * `StandardColors` names the same slots the table defines; the two must
     * agree or the human-facing colour set and the quantiser disagree. Pinning
     * all 16 (not just the blues) so no named colour can silently drift from
     * its slot.
     */
    public function testStandardColorsMatchTableSlots(): void
    {
        $named = [
            StandardColors::$black, StandardColors::$red, StandardColors::$green,
            StandardColors::$yellow, StandardColors::$blue, StandardColors::$magenta,
            StandardColors::$cyan, StandardColors::$white, StandardColors::$brightBlack,
            StandardColors::$brightRed, StandardColors::$brightGreen, StandardColors::$brightYellow,
            StandardColors::$brightBlue, StandardColors::$brightMagenta, StandardColors::$brightCyan,
            StandardColors::$brightWhite,
        ];

        foreach ($named as $slot => $color) {
            [$r, $g, $b] = Color::ANSI16_RGB[$slot];
            self::assertSame(
                \sprintf('#%02x%02x%02x', $r, $g, $b),
                $color->toHex(),
                "StandardColors slot {$slot} must equal Color::ANSI16_RGB[{$slot}]",
            );
        }
    }

    /**
     * @return array<string,array{int}>
     */
    public static function divergentProbeProvider(): array
    {
        return [
            'pure blue #0000ff'   => [0x0000ff],
            'blue2 #0000ee'       => [0x0000ee],
            'dodgerblue #1e90ff'  => [0x1e90ff],
            'xterm-antique #0000cd' => [0x0000cd],
            'bright blue #5c5cff' => [0x5c5cff],
        ];
    }

    /**
     * The heart of the fix: for each historically divergent colour, palette's
     * quantiser must now agree with core's — and dodgerblue must NOT land on
     * cyan (slot 6), the corruption the old table produced.
     */
    public function testQuantiserAgreesWithCoreOnDivergentProbes(): void
    {
        $nearest = new ReflectionMethod(\SugarCraft\Core\Util\Color::class, 'nearestAnsi16');
        $nearest->setAccessible(true);

        foreach (self::divergentProbeProvider() as $label => [$hex]) {
            $r = ($hex >> 16) & 0xff;
            $g = ($hex >> 8) & 0xff;
            $b = $hex & 0xff;

            $paletteIdx = Color::fromHex($hex)->toAnsi16Index();
            $coreIdx = $nearest->invoke(
                \SugarCraft\Core\Util\Color::rgb($r, $g, $b),
            );

            self::assertSame(
                $coreIdx,
                $paletteIdx,
                "{$label}: palette slot {$paletteIdx} must equal core slot {$coreIdx}",
            );
        }
    }

    /**
     * Explicit guard for the dodgerblue→cyan regression the old table produced.
     */
    public function testDodgerblueDoesNotDegradeToCyan(): void
    {
        $idx = Color::fromHex(0x1e90ff)->toAnsi16Index();
        self::assertNotSame(6, $idx, 'dodgerblue must not quantise to cyan (slot 6)');
        self::assertSame(12, $idx, 'dodgerblue belongs to bright blue (slot 12) on the canonical table');
    }
}

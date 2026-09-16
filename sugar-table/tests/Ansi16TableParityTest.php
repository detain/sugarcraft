<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Util\Color as CoreColor;
use SugarCraft\Table\Column;
use SugarCraft\Table\Table;

/**
 * Tripwire pinning sugar-table's SGR→RGB decode (Table::ansiColorToRgb(),
 * reached through Table::parseAnsiToStyle() for codes 30-37/40-47/90-97/100-107)
 * to candy-core's canonical xterm ANSI-16 table.
 *
 * Historically parseAnsiToStyle() carried per-case 256-cube literals and
 * color256ToRgb() duplicated that exact table for the 38;5;n (n<16) path,
 * which forked slot 4 to #0000CC and slot 12 to #0000FF — matching neither
 * the canon (blue2 #0000EE / rgb:5c/5c/ff #5C5CFF, xterm main.h
 * DEF_COLOR4/DEF_COLOR12) nor each other — and pinned slot 7 to #CCCCCC
 * (the cube's "white") instead of xterm's #E5E5E5. Both paths now index
 * {@see CoreColor::ANSI16_RGB} through the single shared decoder; these
 * assertions cover all 16 slots across all four code ranges so no future
 * slot can silently drift again. Mirrors
 * sugar-toast/tests/Ansi16TableParityTest.php (PR #1436).
 */
final class Ansi16TableParityTest extends TestCase
{
    private Table $table;

    private ReflectionMethod $decode;

    private ReflectionMethod $parse;

    protected function setUp(): void
    {
        $this->table = Table::fromColumns([Column::new('id', 'ID', 5)]);

        $this->decode = new ReflectionMethod(Table::class, 'ansiColorToRgb');
        $this->decode->setAccessible(true);

        $this->parse = new ReflectionMethod(Table::class, 'parseAnsiToStyle');
        $this->parse->setAccessible(true);
    }

    /**
     * Pack the canonical triple for one slot into the 24-bit int the decoder
     * returns, so the expectation is read from the canon rather than restated.
     */
    private static function canon(int $slot): int
    {
        [$r, $g, $b] = CoreColor::ANSI16_RGB[$slot];

        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Every one of the 16 slots must decode to the canonical triple.
     */
    public function testDecodeIsElementWiseEqualToCore(): void
    {
        foreach (CoreColor::ANSI16_RGB as $slot => [$r, $g, $b]) {
            $packed = $this->decode->invoke($this->table, $slot % 8, $slot >= 8);
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
        self::assertSame(
            0x0000ee,
            $this->decode->invoke($this->table, 4, false),
            'SGR 34 must decode to blue2 #0000EE (xterm DEF_COLOR4)',
        );
        self::assertSame(
            0x5c5cff,
            $this->decode->invoke($this->table, 4, true),
            'SGR 94 must decode to rgb:5c/5c/ff #5C5CFF (xterm DEF_COLOR12)',
        );
    }

    /**
     * End-to-end through the real entry point: each SGR code in the four
     * standard/bright ranges must land on its canon slot.
     *
     * @return array<string, array{string, int, bool}>
     */
    public static function sgrRanges(): array
    {
        // base code → [bright half?, background range?]
        $ranges = [30 => [false, false], 40 => [false, true], 90 => [true, false], 100 => [true, true]];
        $cases = [];
        foreach ($ranges as $base => [$bright, $isBg]) {
            for ($slot = 0; $slot < 8; $slot++) {
                $code = $base + $slot;
                $cases["sgr-{$code}"] = [(string) $code, $bright ? 8 + $slot : $slot, $isBg];
            }
        }

        return $cases;
    }

    /**
     * @param string $code   the bare SGR code fed to the parser
     * @param int    $slot   the ANSI-16 slot it names
     * @param bool   $isBg   true for the 40-47/100-107 background ranges
     *
     * @dataProvider sgrRanges
     */
    public function testSgrCodesResolveToCanonicalSlots(string $code, int $slot, bool $isBg): void
    {
        $style = $this->parse->invoke($this->table, $code);
        $actual = $isBg ? $style->bg() : $style->fg();

        self::assertNotNull($actual, "SGR {$code} must resolve to a concrete colour");
        self::assertSame(
            self::canon($slot),
            $actual,
            "SGR {$code} must resolve to ANSI-16 slot {$slot} (xterm canon)",
        );
    }

    /**
     * The deleted 256-cube approximation must stay deleted: none of the eight
     * standard slots (nor their bright counterparts) may resolve to one of the
     * old cube literals that the canon supersedes.
     */
    public function testOldCubeLiteralsAreGone(): void
    {
        $superseded = [
            0xcc0000, 0x00cc00, 0xcccc00, 0x0000cc,
            0xcc00cc, 0x00cccc, 0xcccccc, 0x808080, 0x0000ff,
        ];

        foreach (CoreColor::ANSI16_RGB as $slot => $_) {
            $packed = $this->decode->invoke($this->table, $slot % 8, $slot >= 8);
            self::assertNotContains(
                $packed,
                $superseded,
                "slot {$slot} regressed to a deleted 256-cube literal",
            );
        }
    }

    /**
     * The 38;5;n (n<16) path and its bare SGR spelling must agree slot for
     * slot — they share one decoder now that color256ToRgb()'s private copy of
     * the cube table is deleted.
     */
    public function testIndexedPaletteAgreesWithStandardCodes(): void
    {
        foreach (CoreColor::ANSI16_RGB as $slot => $_) {
            $expected = self::canon($slot);

            $indexed = $this->parse->invoke($this->table, "38;5;{$slot}")->fg();
            self::assertSame($expected, $indexed, "38;5;{$slot} diverged from the canon");

            $bgIndexed = $this->parse->invoke($this->table, "48;5;{$slot}")->bg();
            self::assertSame($expected, $bgIndexed, "48;5;{$slot} diverged from the canon");

            $bare = $this->parse->invoke($this->table, (string) ($slot < 8 ? 30 + $slot : 90 + $slot - 8))->fg();
            self::assertSame($expected, $bare, "bare SGR for slot {$slot} diverged from the canon");
        }
    }

    /**
     * Canon adoption must not disturb the malformed-input edge: a negative
     * `38;5;-n` index is below the table, where the pre-canon decoder defaulted
     * to black. Pin it so the shared decoder's defensive white slot cannot
     * leak into this path.
     */
    public function testMalformedNegativeIndexKeepsBlackDefault(): void
    {
        $fg = $this->parse->invoke($this->table, '38;5;-5')->fg();
        self::assertSame(0x000000, $fg, '38;5;-5 must keep the pre-canon black default');

        $bg = $this->parse->invoke($this->table, '48;5;-1')->bg();
        self::assertSame(0x000000, $bg, '48;5;-1 must keep the pre-canon black default');
    }
}

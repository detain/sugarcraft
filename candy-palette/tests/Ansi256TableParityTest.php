<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\NearestColor;

/**
 * Tripwire pinning candy-palette's ANSI-256 lookup table to ONE source of truth
 * after audit #1 deleted the matcher's private even-51-step cube.
 *
 * Historically three 256-color tables coexisted: NearestColor::palette256()
 * built its cube at channel levels 0,51,102,153,204,255 while
 * Color::fromAnsi256Index() and candy-core's Util\Color::ansi256() used the
 * canonical xterm levels 0,95,135,175,215,255 — a 216-entry, 721-channel-value
 * divergence. The matcher therefore ranked colors against a palette no real
 * terminal renders, and encode→match round-trips disagreed with themselves.
 *
 * The table is now DERIVED from Color::fromAnsi256Index(), and these pins keep
 * the triangle (palette matcher table ↔ palette encoder ↔ candy-core decoder)
 * from silently splitting again.
 *
 * @see \SugarCraft\Core\Util\Color::ansi256()
 */
final class Ansi256TableParityTest extends TestCase
{
    /**
     * Every cube/grey entry 16-255 of the matcher table must equal the
     * palette's own encoder decode.
     */
    public function testMatcherTableIsTheEncoderDecode(): void
    {
        $table = NearestColor::palette256();

        for ($index = 16; $index < 256; $index++) {
            $expected = Color::fromAnsi256Index($index);
            self::assertSame(
                [$expected->r, $expected->g, $expected->b],
                [$table[$index]->r, $table[$index]->g, $table[$index]->b],
                "palette256()[{$index}] diverged from Color::fromAnsi256Index({$index})",
            );
        }
    }

    /**
     * Cross-library leg: the same 240 slots must equal candy-core's canonical
     * decoder — the table candy-core renders with.
     */
    public function testMatcherTableMatchesCandyCoreAnsi256(): void
    {
        $table = NearestColor::palette256();

        for ($index = 16; $index < 256; $index++) {
            $core = \SugarCraft\Core\Util\Color::ansi256($index);
            self::assertSame(
                [$core->r, $core->g, $core->b],
                [$table[$index]->r, $table[$index]->g, $table[$index]->b],
                "palette256()[{$index}] diverged from candy-core ansi256({$index})",
            );
        }
    }

    /**
     * The canonical cube levels themselves, pinned so a future edit to either
     * table trips even if both sides move together away from xterm.
     */
    public function testCubeChannelsAreTheSixXtermLevels(): void
    {
        $levels = [0, 95, 135, 175, 215, 255];
        $table = NearestColor::palette256();

        foreach ([16, 46, 196, 231] as $index) {
            $color = $table[$index];
            self::assertContains($color->r, $levels, "index {$index} red byte");
            self::assertContains($color->g, $levels, "index {$index} green byte");
            self::assertContains($color->b, $levels, "index {$index} blue byte");
        }

        // The two corners that made the old 51-step table detectably wrong.
        self::assertSame('#00005f', $table[17]->toHex(), 'index 17 = cube (0,0,1)');
        self::assertSame('#af5f00', $table[130]->toHex(), 'index 130 = cube (3,1,0)');
    }

    /**
     * On exact palette nodes the encoder and the Euclidean matcher must agree
     * slot-for-slot — the property the two divergent tables used to break
     * (a node the encoder produced could rank non-zero against the matcher's
     * private cube).
     */
    public function testEncoderAndMatcherAgreeOnExactNodes(): void
    {
        $matcher = new NearestColor();

        // Nodes chosen to NOT overlap the ANSI-16 set (cube red #ff0000 and
        // white #ffffff tie with bright slots 9/15 and correctly resolve to
        // the lower index — that rule is pinned in NearestColorTest).
        foreach ([17, 59, 130, 232, 244] as $index) {
            $node = NearestColor::palette256()[$index];
            self::assertSame($index, $node->toAnsi256Index(), "encoder round-trip at {$index}");
            self::assertSame($index, $matcher->ansi256($node), "matcher fixed point at {$index}");
        }
    }
}

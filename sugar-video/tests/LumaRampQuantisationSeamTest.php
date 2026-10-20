<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\AsciiRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;

/**
 * MICRO-14: floor-vs-round glyph quantisation seam — differential behaviour pin.
 *
 * Two live glyph pickers exist in this library's render path and they do NOT
 * agree:
 *  - LumaRamp::char() (used by Player::renderCells()) walks the legacy FLOOR
 *    table: index = floor(luma * len / 256), i.e. the full table length;
 *  - the AsciiRenderer bridge delegates to sugar-image's canonical renderer,
 *    which ROUNDS: index = round(luma / 255 * (len - 1)).
 *
 * They are not a one-step-apart pair: at the bottom the floor walk lags the
 * round walk (darker glyphs), in the middle it leads (brighter glyphs), and
 * both stay monotone. The ledger's rule is "differential pin BEFORE any
 * flip" — this file is that pin, so a future alignment of either side is a
 * deliberate, visible change instead of silent glyph drift.
 */
final class LumaRampQuantisationSeamTest extends TestCase
{
    /**
     * The exact lumas (standard ramp) where the legacy floor walk and the
     * canonical round walk select different glyphs — 59 of 256.
     *
     * @var list<int>
     */
    private const FLOOR_ROUND_DIVERGENCE = [
        10, 11, 12, 13, 14, 15, 16, 17,
        28, 29, 30, 31, 32, 33, 34,
        46, 47, 48, 49, 50, 51,
        64, 65, 66, 67, 68,
        82, 83, 84, 85,
        101, 102, 119, 154,
        171, 172, 173,
        188, 189, 190, 191,
        205, 206, 207, 208, 209,
        222, 223, 224, 225, 226, 227,
        239, 240, 241, 242, 243, 244, 245,
    ];

    /** Legacy floor index: floor(luma * len / 256). */
    private static function floorIndex(int $luma, int $len): int
    {
        return (int) (($luma * $len) / 256);
    }

    /** Canonical round index: round(luma / 255 * (len - 1)). */
    private static function roundIndex(int $luma, int $len): int
    {
        return (int) round($luma / 255 * ($len - 1));
    }

    public function testTheLegacyFloorWalkAndTheCanonicalRoundWalkDisagreeOnExactlyTheseLumas(): void
    {
        $ramp = LumaRamp::CHARS2;
        $len = strlen($ramp);

        // Derived from the LIVE pickers — LumaRamp::char() (the floor walk
        // Player paints with) against the canonical round glyph — so
        // aligning either side reddens this list, not just the formula.
        $differing = [];
        for ($luma = 0; $luma <= 255; $luma++) {
            if (LumaRamp::char((float) $luma) !== $ramp[self::roundIndex($luma, $len)]) {
                $differing[] = $luma;
            }
        }

        self::assertSame(self::FLOOR_ROUND_DIVERGENCE, $differing);
        self::assertCount(59, $differing);
    }

    /**
     * LumaRamp::char() really is the floor walk — not merely close to it.
     */
    public function testLumaRampCharWalksTheFloorTable(): void
    {
        $ramp = LumaRamp::CHARS2;
        $len = strlen($ramp);

        for ($luma = 0; $luma <= 255; $luma++) {
            self::assertSame(
                $ramp[self::floorIndex($luma, $len)],
                LumaRamp::char((float) $luma),
                "char($luma) must index the floor table",
            );
        }
    }

    /**
     * The bridge (AsciiRenderer -> sugar-image) really is the round walk,
     * measured end-to-end on a 256x1 grey strip: grey channel value i has
     * BT.601 luma exactly i ((77+150+29) * i >> 8 === i), so cell i of the
     * rendered row is the glyph for luma i.
     */
    public function testTheBridgePaintsTheCanonicalRoundWalkForEveryLuma(): void
    {
        $ramp = LumaRamp::CHARS2;
        $len = strlen($ramp);

        $bytes = '';
        for ($luma = 0; $luma <= 255; $luma++) {
            $bytes .= chr($luma) . chr($luma) . chr($luma);
        }

        $out = rtrim(
            (new AsciiRenderer('standard'))->render(new RgbFrame($bytes, 256, 1), Mode::Ascii),
            "\n",
        );

        self::assertSame(256, strlen($out), 'one cell per pixel, one row');

        for ($luma = 0; $luma <= 255; $luma++) {
            self::assertSame(
                $ramp[self::roundIndex($luma, $len)],
                $out[$luma],
                "bridge cell $luma must use the round table",
            );
        }
    }

    /**
     * The direction of the seam: below the middle the floor walk is DARKER
     * (33 lumas), at and above it the floor walk is BRIGHTER (26 lumas).
     * Pinning the split stops a one-sided "just add one" fix from passing.
     */
    public function testTheSeamRunsInBothDirections(): void
    {
        $ramp = LumaRamp::CHARS2;
        $len = strlen($ramp);

        $floorDarker = 0;
        $floorBrighter = 0;
        foreach (self::FLOOR_ROUND_DIVERGENCE as $luma) {
            $floor = self::floorIndex($luma, $len);
            $round = self::roundIndex($luma, $len);
            $floor < $round ? $floorDarker++ : $floorBrighter++;
        }

        self::assertSame(33, $floorDarker);
        self::assertSame(26, $floorBrighter);
    }

    /**
     * Both walks are monotone non-decreasing — the seam is a glyph choice,
     * never an inversion that would scramble a gradient.
     */
    public function testBothWalksAreMonotone(): void
    {
        $len = strlen(LumaRamp::CHARS2);

        $prevFloor = -1;
        $prevRound = -1;
        for ($luma = 0; $luma <= 255; $luma++) {
            $floor = self::floorIndex($luma, $len);
            $round = self::roundIndex($luma, $len);
            self::assertGreaterThanOrEqual($prevFloor, $floor);
            self::assertGreaterThanOrEqual($prevRound, $round);
            $prevFloor = $floor;
            $prevRound = $round;
        }
    }

    /**
     * Every named ramp carries the same seam — the pin is not standard-only.
     */
    public function testEveryNamedRampCarriesTheSeam(): void
    {
        $counts = [];
        foreach ([LumaRamp::CHARS1, LumaRamp::CHARS2, LumaRamp::CHARS3] as $ramp) {
            $len = strlen($ramp);
            $differing = 0;
            for ($luma = 0; $luma <= 255; $luma++) {
                if (self::floorIndex($luma, $len) !== self::roundIndex($luma, $len)) {
                    $differing++;
                }
            }
            $counts[] = $differing;
        }

        self::assertSame([54, 59, 50], $counts);
    }
}

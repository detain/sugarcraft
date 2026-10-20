<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Capability;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Scale;

/**
 * C-10 fix (lane f8): Scale::ShrinkToFit's refused upscale used to re-happen
 * one layer below — Mosaic kept the native-size image, render() re-derived the
 * full box, and PixelGrid::fromGd resample-filled it anyway. Measured pre-fix:
 * 40×40 → 80×80 half-block was BYTE-IDENTICAL to Fit (236,879 B, 80 full rows,
 * zero pad rows). The pad canvas in Mosaic::applyScale wires the refusal
 * through; these tests pin the centred, blank-padded bytes.
 */
final class MosaicShrinkToFitPadTest extends TestCase
{
    /**
     * The pen-delta row shape for fully-opaque red cells (A-01): the pair of
     * pens is armed ONCE per row, identical neighbours ride the tracked pen,
     * and the row closes with a scoped restore — not the pre-A-01 per-cell
     * `ESC[0m`. RED_ARMS arms both pens; a trailing blank run drops the
     * background mid-row (BG_RESTORE) and leaves only the fg restore.
     */
    private const RED_ARMS = "\x1b[38;2;255;0;0m\x1b[48;2;255;0;0m";
    private const BG_RESTORE = "\x1b[49m";
    private const ROW_RESTORE = "\x1b[39;49m";
    private const ROW_RESTORE_FG = "\x1b[39m";

    /** $cells opaque red glyphs with the pens armed once and the row restored. */
    private static function redContent(int $cells): string
    {
        return self::RED_ARMS . str_repeat("\u{2580}", $cells) . self::ROW_RESTORE;
    }

    private function solidRed(int $w, int $h): ImageSource
    {
        $rgb = str_repeat("\xff\x00\x00", $w * $h);

        return ImageSource::fromRgb($rgb, $w, $h, false);
    }

    private function halfBlock(Scale $scale): Mosaic
    {
        return Mosaic::halfBlock()->withScale($scale);
    }

    public function testShrinkToFitCentresNativeContentWithBlankPad(): void
    {
        $out = $this->halfBlock(Scale::ShrinkToFit)->render($this->solidRed(40, 40), 80, 80);

        $blankRow = str_repeat(' ', 80);
        // Centre of the 80×160 sub-pixel canvas: padX = (80-40)/2 = 20 cols,
        // padY = (160-40)/2 = 60 sub-rows = 30 cell rows. Content spans
        // cell rows 30..49 (40 px = 40 sub-rows = 20 cell rows) and cols 20..59.
        $contentRow = str_repeat(' ', 20) . self::RED_ARMS . str_repeat("\u{2580}", 40) . self::BG_RESTORE . str_repeat(' ', 20) . self::ROW_RESTORE_FG;
        $expectedRows = array_merge(
            array_fill(0, 30, $blankRow),
            array_fill(0, 20, $contentRow),
            array_fill(0, 30, $blankRow),
        );

        $this->assertSame(implode("\n", $expectedRows), $out, 'entire 80×80 frame byte-pinned');
    }

    public function testShrinkToFitRefusedUpscaleNoLongerEqualsFit(): void
    {
        $image = $this->solidRed(40, 40);

        $fit = $this->halfBlock(Scale::Fit)->render($image, 80, 80);

        // Fit's documented shape for this pair: full-box red, every cell the
        // same opaque block — 80 rows × 80 cells, no blanks. (Unchanged by the
        // fix; pinned so a future Fit rework reds this lane's contrast claim.)
        $this->assertSame(
            implode("\n", array_fill(0, 80, self::redContent(80))),
            $fit,
        );

        $shrink = $this->halfBlock(Scale::ShrinkToFit)->render($image, 80, 80);
        $this->assertNotSame($fit, $shrink, 'refused upscale must be observable end-to-end');
        $this->assertStringContainsString(str_repeat(' ', 80) . "\n", $shrink);
    }

    /**
     * Exact fit (width already at the box) still pads: the half-block native
     * density turns a square source into a 2:1 sub-pixel canvas, so the
     * vertical remainder is blank even when the horizontal one is not.
     */
    public function testShrinkToFitExactWidthStillPadsVertically(): void
    {
        $out = $this->halfBlock(Scale::ShrinkToFit)->render($this->solidRed(40, 40), 40, 40);

        $contentRow = self::redContent(40);
        // Canvas 40×80 sub-rows, content rows 20..59 → cell rows 10..29.
        $expectedRows = array_merge(
            array_fill(0, 10, str_repeat(' ', 40)),
            array_fill(0, 20, $contentRow),
            array_fill(0, 10, str_repeat(' ', 40)),
        );

        $this->assertSame(implode("\n", $expectedRows), $out);
    }

    /**
     * Odd pad splits floor-left/top, extra right/bottom: 2×4 content in a
     * 7×8 box → padX = 2 (right 3), padY = 6 sub-rows (cell rows 3..4).
     */
    public function testShrinkToFitOddPadFloorsLeftTopCentres(): void
    {
        $out = $this->halfBlock(Scale::ShrinkToFit)->render($this->solidRed(2, 4), 7, 8);

        $rows = explode("\n", $out);
        $this->assertCount(8, $rows);
        $expectedRows = array_merge(
            array_fill(0, 3, str_repeat(' ', 7)),
            [
                str_repeat(' ', 2) . self::RED_ARMS . "\u{2580}\u{2580}" . self::BG_RESTORE . str_repeat(' ', 3) . self::ROW_RESTORE_FG,
                str_repeat(' ', 2) . self::RED_ARMS . "\u{2580}\u{2580}" . self::BG_RESTORE . str_repeat(' ', 3) . self::ROW_RESTORE_FG,
            ],
            array_fill(0, 3, str_repeat(' ', 7)),
        );

        $this->assertSame(implode("\n", $expectedRows), $out);
    }

    /**
     * The SHRINK leg (source larger than the box) keeps its shipped shape:
     * resized to fit, height re-derived, no pad canvas, no box filling.
     */
    public function testShrinkToFitShrinkLegStaysUnpadded(): void
    {
        $image = $this->solidRed(200, 40);

        $out = $this->halfBlock(Scale::ShrinkToFit)->render($image, 80, 80);

        // factor = min(80/200, 80/40) = 0.4 → 80×16 px; aspect re-derivation
        // then yields round(80 / 5.0) = 16 cell rows, every one full-width red.
        $rows = explode("\n", $out);
        $this->assertCount(16, $rows);
        foreach ($rows as $row) {
            $this->assertSame(self::redContent(80), $row);
        }
        $this->assertStringNotContainsString(' ', $out, 'shrink leg must not gain pad');
    }

    /**
     * Honest tier split: QuarterBlockRenderer reads RGB-only quads
     * (PixelGrid::fromGdQuarter), so a transparent pad would paint BLACK cells
     * — a false image, not a blank. The quarter tier therefore keeps the
     * pre-fix fill behaviour (documented in Scale::ShrinkToFit) until nullable
     * quads land (chafa-campaign follow-up S3.1). This pin REDS the day quarter
     * padding is wired, forcing the byte-identity caveat to be updated too.
     */
    public function testShrinkToFitQuarterTierCannotPadAndFallsBackToFill(): void
    {
        $image = $this->solidRed(40, 40);

        $fit = (new Mosaic(new QuarterBlockRenderer(), Capability::universal(), null, null, Scale::Fit))
            ->render($image, 80, 80);
        $shrink = (new Mosaic(new QuarterBlockRenderer(), Capability::universal(), null, null, Scale::ShrinkToFit))
            ->render($image, 80, 80);

        $this->assertSame($fit, $shrink, 'quarter tier: no blank representation yet — documented fallback');
    }

    /**
     * Structural guard on the wiring itself: the pad branch must sit BEFORE
     * the early-return in applyScale (an unchanged dims pair is exactly the
     * refused-upscale shape, so an order swap silently reverts to no-op) and
     * must fire only for ShrinkToFit. Comment-stripped source slice, per the
     * house structural-pin pattern.
     */
    public function testTheRefusedFitsArePaddedNotEarlyReturned(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Mosaic.php');

        $padPos    = strpos($src, 'paddedToFitCanvas($image, $cellW, $cellH)');
        $earlyPos  = strpos($src, '// No transformation needed — return original.');
        $deriveset = strpos($src, 'if (!$boxIsFinal && $this->scale !== Scale::Fill');

        $this->assertIsInt($padPos, 'pad branch must be wired');
        $this->assertIsInt($earlyPos);
        $this->assertLessThan($earlyPos, $padPos, 'pad must precede the unchanged-dims early return');
        $this->assertIsInt($deriveset, 'render() must keep the padded box height explicit');
        $this->assertMatchesRegularExpression(
            '/Scale::ShrinkToFit\s*&&\s*\$image->width <= \$cellW && \$image->height <= \$cellH/',
            $src,
            'pad fires exactly when the fit-legs clamped (src fits the box)',
        );
        $this->assertMatchesRegularExpression(
            '/!\$this->renderer instanceof QuarterBlockRenderer/',
            $src,
            'quarter tier must keep the documented bypass',
        );
    }
}

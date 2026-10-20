<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Scale;

final class ScaleTest extends TestCase
{
    // ─── Scale::Fit ─────────────────────────────────────────────────────────

    /**
     * C-10: Fit scales in BOTH directions — these pins record the honest
     * upscale behaviour (4×2 into 8×8 enlarges 2×); padding was never what
     * Fit did. The shrink-only, enlarge-refusing mode is Scale::ShrinkToFit.
     */
    public function testFitScalesToFitWithinBounds(): void
    {
        // 4×2 image rendered at 8×8 target.
        // aspect = 2.0, factor = min(8/4, 8/2) = min(2, 4) = 2
        // renderW = 4*2 = 8, renderH = 2*2 = 4
        $r = Scale::Fit->computeDimensions(4, 2, 8, 8);

        $this->assertSame(0, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(4, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(4, $r['dstH']);
    }

    public function testFitTallImageScalesToFit(): void
    {
        // 2×4 (aspect 0.5), target 8×8
        // factor = min(8/2, 8/4) = min(4, 2) = 2
        // renderW = 2*2 = 4, renderH = 4*2 = 8
        $r = Scale::Fit->computeDimensions(2, 4, 8, 8);

        $this->assertSame(4, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    public function testFitExactSizeReturnsNoChange(): void
    {
        $r = Scale::Fit->computeDimensions(8, 8, 8, 8);

        $this->assertSame(0, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(8, $r['srcW']);
        $this->assertSame(8, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    // ─── Scale::ShrinkToFit (C-10) ─────────────────────────────────────────

    /**
     * C-10: provider over (src,dst) pairs for the chafa
     * CHAFA_TUCK_SHRINK_TO_FIT behaviour — enlarge refused, shrink honoured,
     * exact fit untouched, 1px edges.
     */
    public static function shrinkToFitProvider(): array
    {
        return [
            // 40×40 icon into 80×80: fits entirely → alignment only, NO upscale.
            'enlarge refused'      => [40, 40, 80, 80, 40, 40],
            // 4×2 into 8×8: the exact pair Fit above upscales 2×; here it stays native.
            'enlarge refused tiny' => [4, 2, 8, 8, 4, 2],
            // 200×100 into 80×80: factor = min(0.4, 0.8) = 0.4 → 80×40.
            'shrink honoured'      => [200, 100, 80, 80, 80, 40],
            // Tall shrink: factor = min(8/2, 8/4)... src 2×16 into 8×8 → 1×8.
            'shrink tall'          => [2, 16, 8, 8, 1, 8],
            // Exact fit: ratio lands on 1 from both legs, nothing moves.
            'exact fit'            => [80, 60, 80, 60, 80, 60],
            // 1px source, generous box: stays 1×1.
            'one pixel source'     => [1, 1, 80, 80, 1, 1],
            // 1px box: source floors to 1×1, never 0.
            'one pixel box'        => [40, 20, 1, 1, 1, 1],
            // Sub-round shrink: 3×1 into 1×1 → factor 1/3 → round(1)=1 (≥1 floor).
            'sub-round shrink'     => [3, 1, 1, 1, 1, 1],
        ];
    }

    /**
     * C-10: ShrinkToFit never enlarges and never exceeds either the box or
     * the source; the src rect always stays full-source (this mode crops nothing).
     *
     * @dataProvider shrinkToFitProvider
     */
    public function testShrinkToFitRefusesToEnlarge(int $sw, int $sh, int $dw, int $dh, int $expW, int $expH): void
    {
        $r = Scale::ShrinkToFit->computeDimensions($sw, $sh, $dw, $dh);

        $this->assertSame(0, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame($sw, $r['srcW']);
        $this->assertSame($sh, $r['srcH']);
        $this->assertSame($expW, $r['dstW']);
        $this->assertSame($expH, $r['dstH']);
        $this->assertLessThanOrEqual($dw, $r['dstW']);
        $this->assertLessThanOrEqual($dh, $r['dstH']);
        $this->assertLessThanOrEqual($sw, $r['dstW'], 'never upscales width');
        $this->assertLessThanOrEqual($sh, $r['dstH'], 'never upscales height');
        $this->assertGreaterThanOrEqual(1, $r['dstW']);
        $this->assertGreaterThanOrEqual(1, $r['dstH']);
    }

    /**
     * C-10: the chafa precondition spelled directly — a source that already
     * fits inside the box comes back at native size (factor == 1 leg-wise).
     */
    public function testShrinkToFitKeepsFittingSourceAtNativeSize(): void
    {
        $r = Scale::ShrinkToFit->computeDimensions(40, 40, 80, 80);

        $this->assertSame(40, $r['dstW']);
        $this->assertSame(40, $r['dstH']);
    }

    // ─── Scale::Fill ─────────────────────────────────────────────────────────

    public function testFillScalesToCoverBounds(): void
    {
        // 4×2 image at 8×8 target.
        // factor = max(8/4, 8/2) = max(2, 4) = 4
        // srcCropW = round(8/4) = 2, srcCropH = round(8/4) = 2
        // srcX = (4-2)/2 = 1, srcY = 0
        $r = Scale::Fill->computeDimensions(4, 2, 8, 8);

        $this->assertSame(1, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(2, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    public function testFillCentersCrop(): void
    {
        // Wide image: 8×2 at 8×8 target.
        // factor = max(8/8, 8/2) = max(1, 4) = 4
        // srcCropW = round(8/4) = 2, srcCropH = round(8/4) = 2
        // srcX = (8-2)/2 = 3, srcY = 0
        $r = Scale::Fill->computeDimensions(8, 2, 8, 8);

        $this->assertSame(3, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(2, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    // ─── Scale::Stretch ─────────────────────────────────────────────────────

    public function testStretchIgnoresAspectRatio(): void
    {
        $r = Scale::Stretch->computeDimensions(4, 2, 8, 8);

        $this->assertSame(0, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(4, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    // ─── Scale::None ───────────────────────────────────────────────────────

    public function testNonePreservesNativeSize(): void
    {
        $r = Scale::None->computeDimensions(4, 2, 8, 8);

        $this->assertSame(0, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(4, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        // dstW/dstH = native image size (no resize applied).
        $this->assertSame(4, $r['dstW']);
        $this->assertSame(2, $r['dstH']);
    }

    // ─── Scale::Crop ───────────────────────────────────────────────────────

    public function testCropCentersAndScales(): void
    {
        // 4×2 image at 8×8 target (aspect ratio mismatch).
        // Crop formula: srcCropW = round(4*8/8*2/4) = 2
        //               srcCropH = round(2*8/8*4/2) = 4 → clamped to 2
        // srcX = round((4-2)/2) = 1, srcY = 0
        $r = Scale::Crop->computeDimensions(4, 2, 8, 8);

        $this->assertSame(1, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(2, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    public function testCropWideSourceCropsWidth(): void
    {
        // 8×2 image at 8×8 target.
        // srcCropW = round(8*8/8*2/8) = 2
        // srcCropH = round(2*8/8*8/8) = 2 → clamped to 2
        // srcX = round((8-2)/2) = 3, srcY = 0
        $r = Scale::Crop->computeDimensions(8, 2, 8, 8);

        $this->assertSame(3, $r['srcX']);
        $this->assertSame(0, $r['srcY']);
        $this->assertSame(2, $r['srcW']);
        $this->assertSame(2, $r['srcH']);
        $this->assertSame(8, $r['dstW']);
        $this->assertSame(8, $r['dstH']);
    }

    public function testCropRectForPortraitSource(): void
    {
        // 100×300 portrait source → 40×20 cells.
        // PHP integer arithmetic: 100*40/20*300/100 = 600 → clamped (600 > 100)
        // srcCropW = 100 (clamped), srcCropH = 50
        // srcX = (100-100)/2 = 0, srcY = (300-50)/2 = 125
        $r = Scale::Crop->computeDimensions(100, 300, 40, 20);

        $this->assertSame(0,   $r['srcX']);
        $this->assertSame(125, $r['srcY']);
        $this->assertSame(100, $r['srcW']);
        $this->assertSame(50,  $r['srcH']);
        $this->assertSame(40,  $r['dstW']);
        $this->assertSame(20,  $r['dstH']);
    }

    public function testCropRectForLandscapeSource(): void
    {
        // 300×100 landscape source → 40×20 cells.
        // PHP integer arithmetic: 300*40/20*100/300 = 200 (not clamped, 200 ≤ 300)
        // srcCropW = 200, srcCropH = 150 → clamped to 100 (150 > 100)
        // srcX = (300-200)/2 = 50, srcY = (100-100)/2 = 0
        $r = Scale::Crop->computeDimensions(300, 100, 40, 20);

        $this->assertSame(50,  $r['srcX']);
        $this->assertSame(0,   $r['srcY']);
        $this->assertSame(200, $r['srcW']);
        $this->assertSame(100, $r['srcH']);
        $this->assertSame(40,  $r['dstW']);
        $this->assertSame(20,  $r['dstH']);
    }

    // ─── Edge cases ────────────────────────────────────────────────────────

    public function testFillExtremeAspectNeverEmitsZeroSidedSourceRect(): void
    {
        // src 100×1 → dst 1×1000: factor = max(1/100, 1000/1) = 1000, so the
        // naive round(dstW/factor) lands on 0 in the width leg; the mirrored
        // call degenerates in the height leg (lane p2, N2 — a 0-sided SOURCE
        // rect reaches imagecrop and GD fails there; only dst was clamped).
        foreach ([[100, 1, 1, 1000], [1, 100, 1000, 1]] as [$sw, $sh, $dw, $dh]) {
            $r = Scale::Fill->computeDimensions($sw, $sh, $dw, $dh);

            $this->assertGreaterThanOrEqual(1, $r['srcW'], "srcW floored at 1 ({$sw}x{$sh} -> {$dw}x{$dh})");
            $this->assertGreaterThanOrEqual(1, $r['srcH'], "srcH floored at 1 ({$sw}x{$sh} -> {$dw}x{$dh})");
            $this->assertGreaterThanOrEqual(0, $r['srcX']);
            $this->assertGreaterThanOrEqual(0, $r['srcY']);
            $this->assertLessThanOrEqual($sw, $r['srcX'] + $r['srcW'], 'rect stays inside the source (width)');
            $this->assertLessThanOrEqual($sh, $r['srcY'] + $r['srcH'], 'rect stays inside the source (height)');
        }
    }

    public function testCropExtremeAspectNeverEmitsZeroSidedSourceRect(): void
    {
        // The same degeneracy through Crop's cancelled-factor math (lane p2,
        // N2): the upper cap existed, the lower floor did not.
        foreach ([[100, 1, 1, 1000], [1, 100, 1000, 1]] as [$sw, $sh, $dw, $dh]) {
            $r = Scale::Crop->computeDimensions($sw, $sh, $dw, $dh);

            $this->assertGreaterThanOrEqual(1, $r['srcW'], "srcW floored at 1 ({$sw}x{$sh} -> {$dw}x{$dh})");
            $this->assertGreaterThanOrEqual(1, $r['srcH'], "srcH floored at 1 ({$sw}x{$sh} -> {$dw}x{$dh})");
            $this->assertGreaterThanOrEqual(0, $r['srcX']);
            $this->assertGreaterThanOrEqual(0, $r['srcY']);
            $this->assertLessThanOrEqual($sw, $r['srcX'] + $r['srcW'], 'rect stays inside the source (width)');
            $this->assertLessThanOrEqual($sh, $r['srcY'] + $r['srcH'], 'rect stays inside the source (height)');
        }
    }

    /**
     * C-11: the old guard echoed src<=0 unchanged, violating the documented
     * src>=1 invariant (and the imagecrop/GD hazard it exists to prevent).
     * A non-positive SOURCE now throws instead of quietly re-writing geometry.
     */
    public static function emptySourceProvider(): array
    {
        return [
            'zero width'      => [0, 200, 40, 20],
            'zero height'     => [200, 0, 40, 20],
            'both zero'       => [0, 0, 0, 0],
            'negative width'  => [-5, 10, 40, 20],
            'negative height' => [10, -5, 40, 20],
        ];
    }

    /**
     * C-11: every mode refuses a degenerate source rect up front.
     *
     * @dataProvider emptySourceProvider
     */
    public function testEmptySourceThrows(int $sw, int $sh, int $dw, int $dh): void
    {
        foreach (Scale::cases() as $mode) {
            $threw = false;
            try {
                $mode->computeDimensions($sw, $sh, $dw, $dh);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, $mode->name . " {$sw}x{$sh}->{$dw}x{$dh} echoed a degenerate source");
        }
    }

    /**
     * C-11: the message names the empty rect so callers can diagnose it.
     */
    public function testEmptySourceRefusalNamesTheRect(): void
    {
        try {
            Scale::Fit->computeDimensions(0, 200, 40, 20);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('0x200', $e->getMessage());
        }
    }

    /**
     * C-11: a degenerate DESTINATION with a legal source keeps the existing
     * intent — dst legs floored at 1, full-source rect echoed.
     */
    public function testZeroDestinationFloorsToOneWithLegalSource(): void
    {
        $r = Scale::Fit->computeDimensions(8, 8, 0, 0);

        $this->assertSame(1, $r['dstW']);
        $this->assertSame(1, $r['dstH']);
        $this->assertSame(8, $r['srcW']);
        $this->assertSame(8, $r['srcH']);
    }

    /**
     * C-11: property-style pin — for every mode and every legal-source pair
     * (including the aspect degeneracies from lane p2 N2), the returned rect
     * can never carry a zero-sided source or destination again.
     */
    public function testEveryModeKeepsThePositiveRectInvariant(): void
    {
        $pairs = [
            [4, 2, 8, 8], [8, 2, 8, 8], [100, 1, 1, 1000], [1, 100, 1000, 1],
            [100, 300, 40, 20], [300, 100, 40, 20], [1, 1, 1, 1], [80, 60, 16, 32],
        ];
        foreach (Scale::cases() as $mode) {
            foreach ($pairs as [$sw, $sh, $dw, $dh]) {
                $r = $mode->computeDimensions($sw, $sh, $dw, $dh);

                $this->assertGreaterThanOrEqual(1, $r['srcW'], $mode->name . " {$sw}x{$sh}->{$dw}x{$dh} srcW");
                $this->assertGreaterThanOrEqual(1, $r['srcH'], $mode->name . " {$sw}x{$sh}->{$dw}x{$dh} srcH");
                $this->assertGreaterThanOrEqual(1, $r['dstW'], $mode->name . " {$sw}x{$sh}->{$dw}x{$dh} dstW");
                $this->assertGreaterThanOrEqual(1, $r['dstH'], $mode->name . " {$sw}x{$sh}->{$dw}x{$dh} dstH");
            }
        }
    }

    public function testAllSixScaleModesExist(): void
    {
        $cases = [Scale::Fit, Scale::ShrinkToFit, Scale::Fill, Scale::Stretch, Scale::None, Scale::Crop];
        $names = ['Fit', 'ShrinkToFit', 'Fill', 'Stretch', 'None', 'Crop'];

        foreach ($names as $i => $name) {
            $this->assertSame($name, $cases[$i]->name);
        }
    }
}

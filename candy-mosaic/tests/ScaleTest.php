<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Scale;

final class ScaleTest extends TestCase
{
    // ─── Scale::Fit ─────────────────────────────────────────────────────────

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

    // ─── Edge cases ────────────────────────────────────────────────────────

    public function testZeroOrNegativeDimensionsReturnsSafeDefaults(): void
    {
        $r = Scale::Fit->computeDimensions(0, 0, 0, 0);

        $this->assertSame(1, $r['dstW']);
        $this->assertSame(1, $r['dstH']);
    }

    public function testAllFiveScaleModesExist(): void
    {
        $cases = [Scale::Fit, Scale::Fill, Scale::Stretch, Scale::None, Scale::Crop];
        $names = ['Fit', 'Fill', 'Stretch', 'None', 'Crop'];

        foreach ($names as $i => $name) {
            $this->assertSame($name, $cases[$i]->name);
        }
    }
}

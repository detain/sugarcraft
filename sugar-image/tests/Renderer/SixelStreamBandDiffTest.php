<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * The band-diff optimisation moves no byte: render and streamed concat both
 * keep their base-tree digests. Split from SixelStreamTest for the reason
 * SixelStreamFixtures gives.
 *
 * @covers \SugarCraft\Image\Renderer\SixelRenderer
 */
final class SixelStreamBandDiffTest extends TestCase
{
    use SixelStreamFixtures;

    /**
     * Representative render corpus with base-tree digests (captured
     * BEFORE the band-diff landed, stream == render on every row). The
     * changed-columns bitset + PaletteIndex palette-hash memo are an
     * optimisation only: any byte moving here is a visual regression, not
     * a speed-up. Covers holes (transparent Atkinson), trailing zero runs
     * (tall-cells None), full-width masks (flat red), 16-colour budgets,
     * and both frozen gradient digests.
     *
     * 2026-11-04: the three null-height entries were re-pinned for the
     * cell-aspect correctness fix (auto height now divides by the cell aspect
     * too — {@see \SugarCraft\Image\CellGeometry}). Movement is geometry-only:
     * each entry reproduces its pre-fix digest when the old auto height is
     * passed explicitly. The five explicit-box entries never moved.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: int|null, 4: string, 5: int}>
     */
    public static function bandDiffCorpus(): array
    {
        return [
            'flat red, default cells' => ['d883ac34846a2a92d12e03f16e4f793e354a4ad9', '8x4_red.png', 8, 4, 'floyd-steinberg', 256],
            'opaque noise, truecolor dither' => ['c79e84bfb8cfee4e0b240e73f62a5908b84f98c4', '500x400_noise.png', 30, 24, 'stucki', 256],
            'transparent hole, atkinson' => ['6d46a90747cb4e053a2be46f711d8870a7250967', '2x2_transparent_halfblock.png', 12, null, 'atkinson', 256],
            'few colours, no dither' => ['0d1c5ecba4b05d7b219a045c5d29634b9e85566f', '500x400_noise.png', 17, null, 'none', 16],
            'aspect-derived height' => ['65e3145d376ef1d23c6d6c3a5ca6f6346819bf28', '8x4_red.png', 13, null, 'floyd-steinberg', 256],
            'tall cell box' => ['aa3e43fa4ad1f7e34db6685a683f23adb1af9464', '500x400_noise.png', 9, 7, 'none', 256],
            'gradient, no dither' => ['c65c8465e501645f8eb6c76bb46a97cf38e948d0', 'gradient_64x64.png', 16, 12, 'none', 256],
            'gradient, floyd-steinberg' => ['47d7e3d7b2e1a1a3e21e363f836d6d03410987f8', 'gradient_64x64.png', 16, 12, 'floyd-steinberg', 256],
        ];
    }

    #[DataProvider('bandDiffCorpus')]
    public function testBandDiffGuardKeepsRepresentativeBytesFrozen(
        string $digest, string $file, int $width, ?int $height, string $dither, int $maxColors,
    ): void {
        $renderer = new SixelRenderer(Dither::from($dither), $maxColors);
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/' . $file);

        $this->assertSame($digest, sha1($renderer->render($image, $width, $height)), $file . ' ' . $dither);
        $this->assertSame($digest, sha1(implode('', $this->collect($renderer, $image, $width, $height))), 'streamed concat rides the same bytes');
    }
}

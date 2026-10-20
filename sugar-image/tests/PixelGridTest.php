<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\PixelGrid;

/**
 * @covers \SugarCraft\Image\PixelGrid
 */
final class PixelGridTest extends TestCase
{
    private string $fixture4x2;

    protected function setUp(): void
    {
        $this->fixture4x2 = __DIR__ . '/fixtures/4x2.png';
        if (!file_exists($this->fixture4x2)) {
            $this->markTestSkipped('Fixture tests/fixtures/4x2.png missing');
        }
    }

    public function testFromGdQuarterReturnsGridStructure(): void
    {
        $image = ImageSource::fromFile($this->fixture4x2);
        $img = imagecreatefrompng($this->fixture4x2);
        if ($img === false) {
            $this->fail('Could not create GD image from fixture');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $grid = PixelGrid::fromGdQuarter($img, 4, 2);

        imagedestroy($img);

        $this->assertSame(4, $grid->cellW);
        $this->assertSame(2, $grid->cellH);
        $this->assertNotEmpty($grid->cells);

        // Each cell should have 4 quadrants (ul, ur, ll, lr), each an
        // [r, g, b, ?int alpha] quad (A-05a: null alpha = transparent).
        foreach ($grid->cells as $row) {
            foreach ($row as $cell) {
                $this->assertCount(4, $cell);
                foreach ($cell as $quadrant) {
                    $this->assertCount(4, $quadrant);
                    [$r, $g, $b, $a] = $quadrant;
                    $this->assertGreaterThanOrEqual(0, $r);
                    $this->assertLessThanOrEqual(255, $r);
                    $this->assertGreaterThanOrEqual(0, $g);
                    $this->assertLessThanOrEqual(255, $g);
                    $this->assertGreaterThanOrEqual(0, $b);
                    $this->assertLessThanOrEqual(255, $b);
                    $this->assertTrue($a === null || ($a >= 0 && $a <= 126));
                }
            }
        }
    }

    public function testFromGdQuarterGridDimensions(): void
    {
        $image = ImageSource::fromFile($this->fixture4x2);
        $img = imagecreatefrompng($this->fixture4x2);
        if ($img === false) {
            $this->fail('Could not create GD image from fixture');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $grid = PixelGrid::fromGdQuarter($img, 2, 1);

        imagedestroy($img);

        $this->assertCount(1, $grid->cells); // cellH = 1 row
        $this->assertCount(2, $grid->cells[0]); // cellW = 2 columns
    }

    public function testFromStringCreatesGrid(): void
    {
        $bytes = file_get_contents($this->fixture4x2);
        if ($bytes === false) {
            $this->fail('Could not read fixture');
        }

        $grid = PixelGrid::fromString($bytes, 4, 2);

        $this->assertSame(4, $grid->cellW);
        $this->assertSame(2, $grid->cellH);
        $this->assertNotEmpty($grid->cells);
    }

    /**
     * A-05a: fromGdQuarter must expose transparency per quad (alpha null)
     * instead of silently handing back the stored RGB of a transparent
     * pixel — the old colour-bits-only read turned a transparent-black
     * quadrant into a black quad fed to the renderer.
     */
    public function testFromGdQuarterFlagsTransparentQuadsAsNullAlpha(): void
    {
        $gd = imagecreatetruecolor(2, 2);
        self::assertNotFalse($gd);
        imagealphablending($gd, false);

        $opaque = imagecolorallocatealpha($gd, 10, 20, 30, 0);
        $ghost  = imagecolorallocatealpha($gd, 0, 0, 0, 127);
        self::assertNotFalse($opaque);
        self::assertNotFalse($ghost);
        imagesetpixel($gd, 0, 0, $opaque); // ul
        imagesetpixel($gd, 1, 0, $ghost);  // ur
        imagesetpixel($gd, 0, 1, $ghost);  // ll
        imagesetpixel($gd, 1, 1, $opaque); // lr
        imagesavealpha($gd, true);

        $grid = PixelGrid::fromGdQuarter($gd, 1, 1);
        imagedestroy($gd);

        [$ul, $ur, $ll, $lr] = $grid->cells[0][0];
        $this->assertSame([10, 20, 30, 0], $ul);
        $this->assertSame([0, 0, 0, null], $ur, 'fully transparent quad reads null alpha');
        $this->assertSame([0, 0, 0, null], $ll);
        $this->assertSame([10, 20, 30, 0], $lr);
    }
}

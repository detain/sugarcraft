<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\PixelGrid;

/**
 * @covers \SugarCraft\Mosaic\PixelGrid
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

        // Each cell should have 4 quadrants (ul, ur, ll, lr)
        foreach ($grid->cells as $row) {
            foreach ($row as $cell) {
                $this->assertCount(4, $cell);
                foreach ($cell as $quadrant) {
                    $this->assertCount(3, $quadrant);
                    [$r, $g, $b] = $quadrant;
                    $this->assertGreaterThanOrEqual(0, $r);
                    $this->assertLessThanOrEqual(255, $r);
                    $this->assertGreaterThanOrEqual(0, $g);
                    $this->assertLessThanOrEqual(255, $g);
                    $this->assertGreaterThanOrEqual(0, $b);
                    $this->assertLessThanOrEqual(255, $b);
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
}

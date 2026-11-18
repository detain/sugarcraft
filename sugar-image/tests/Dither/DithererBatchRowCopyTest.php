<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither\Ditherer;

/**
 * Rank 32: the base-row loader reads a row in one batch copy and makes the
 * row-invariant decisions (hole census, flatten target, texture) once per row.
 * The pins below are computed from the raw ARGB values by hand, not by the
 * loader, so a regression in the batch shape shows up as a value change.
 *
 * @covers \SugarCraft\Image\Dither\Ditherer
 */
final class DithererBatchRowCopyTest extends TestCase
{
    /** @return array{0: list<array{float,float,float}>, 1: array<int,bool>} */
    private function loadRow(Ditherer $ditherer, \GdImage $img, int $w, int $offset): array
    {
        $method = new \ReflectionMethod(Ditherer::class, 'loadBaseRow');

        return $method->invoke($ditherer, $img, 0, $w, $offset, null, 0);
    }

    private function fixture(): \GdImage
    {
        $img = imagecreatetruecolor(4, 1);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        // GD alpha: 0 opaque, 127 fully transparent.
        imagesetpixel($img, 0, 0, imagecolorallocatealpha($img, 200, 100, 50, 0));
        imagesetpixel($img, 1, 0, imagecolorallocatealpha($img, 10, 20, 30, 64));   // semi-transparent
        imagesetpixel($img, 2, 0, imagecolorallocatealpha($img, 1, 2, 3, 127));     // hole
        imagesetpixel($img, 3, 0, imagecolorallocatealpha($img, 90, 90, 90, 0));

        return $img;
    }

    public function testRowCopyWithoutBackgroundKeepsRawColoursAndCensusesHoles(): void
    {
        $img = $this->fixture();
        [$row, $holes] = $this->loadRow(new Ditherer(), $img, 4, 1);

        $this->assertSame([[200.0, 100.0, 50.0], [10.0, 20.0, 30.0], [1.0, 2.0, 3.0], [90.0, 90.0, 90.0]], $row);
        $this->assertSame([0 => false, 1 => false, 2 => true, 3 => false], $holes);
    }

    public function testFlattenTargetAppliesOnlyToSemiTransparentNonHolePixels(): void
    {
        $img = $this->fixture();
        $bg  = new Color(255, 255, 255);
        [$row, $holes] = $this->loadRow(new Ditherer(flattenBackground: $bg), $img, 4, 1);

        [$r, $g, $b] = Ditherer::flattenOnto(10, 20, 30, 64, $bg);
        $this->assertSame([(float) $r, (float) $g, (float) $b], $row[1]);
        // A hole is never flattened: its raw colour survives.
        $this->assertSame([1.0, 2.0, 3.0], $row[2]);
        // Opaque pixels are untouched by the flatten target.
        $this->assertSame([200.0, 100.0, 50.0], $row[0]);
        $this->assertTrue($holes[2]);
        imagedestroy($img);
    }

    public function testOffsetZeroReturnsNoHoleCensus(): void
    {
        $img = $this->fixture();
        [$row, $holes] = $this->loadRow(new Ditherer(), $img, 4, 0);

        $this->assertCount(4, $row);
        $this->assertSame([], $holes, 'the census runs only on the offset-1 (alpha) pass');
        imagedestroy($img);
    }

    public function testRowIsCopiedInSourceOrder(): void
    {
        // The batch copy must keep source order across the whole row, not just the
        // first pixels, or every later accumulator lands in the wrong column.
        $img = imagecreatetruecolor(16, 1);
        for ($x = 0; $x < 16; $x++) {
            imagesetpixel($img, $x, 0, imagecolorallocate($img, $x * 15, 0, 0));
        }
        [$row] = $this->loadRow(new Ditherer(), $img, 16, 0);

        $this->assertSame(array_map(static fn (int $x): float => (float) ($x * 15), range(0, 15)), array_column($row, 0));
        imagedestroy($img);
    }
}

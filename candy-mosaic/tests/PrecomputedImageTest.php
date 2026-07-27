<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\PrecomputedImage;

final class PrecomputedImageTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $bytes = "\x1b[1mhello\x1b[0m";
        $img = new PrecomputedImage($bytes, 40, 20);

        $this->assertSame($bytes, $img->bytes());
        $this->assertSame(40, $img->cellWidth());
        $this->assertSame(20, $img->cellHeight());
    }

    public function testBytesReturnsExactBytes(): void
    {
        $raw = "\x1b[38;2;255;0;0mX\x1b[0m";
        $img = new PrecomputedImage($raw, 1, 1);

        $this->assertSame($raw, $img->bytes());
    }

    public function testCellWidthReturnsWidth(): void
    {
        $img = new PrecomputedImage('', 80, 24);

        $this->assertSame(80, $img->cellWidth());
    }

    public function testCellHeightReturnsHeight(): void
    {
        $img = new PrecomputedImage('', 80, 24);

        $this->assertSame(24, $img->cellHeight());
    }

    public function testEmptyBytesArePreserved(): void
    {
        $img = new PrecomputedImage('', 10, 10);

        $this->assertSame('', $img->bytes());
    }

    public function testImmutabilityOfAccessors(): void
    {
        $img = new PrecomputedImage('bytes', 10, 20);

        $this->assertSame('bytes', $img->bytes());
        $this->assertSame('bytes', $img->bytes(), 'bytes() is idempotent');
        $this->assertSame(10, $img->cellWidth());
        $this->assertSame(10, $img->cellWidth(), 'cellWidth() is idempotent');
        $this->assertSame(20, $img->cellHeight());
        $this->assertSame(20, $img->cellHeight(), 'cellHeight() is idempotent');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use SugarCraft\Animation\Frame;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    public function testWidthZeroForEmptyCells(): void
    {
        $f = Frame::new([]);
        $this->assertSame(0, $f->width());
    }

    public function testWidthReturnsFirstRowCount(): void
    {
        $f = Frame::new([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255], [128, 128, 128]],
        ]);
        $this->assertSame(2, $f->width());
    }

    public function testHeightReturnsRowCount(): void
    {
        $f = Frame::new([
            [[255, 0, 0]],
            [[0, 255, 0]],
            [[0, 0, 255]],
        ]);
        $this->assertSame(3, $f->height());
    }

    public function testCellsAreStoredReadonly(): void
    {
        $cells = [[[255, 0, 0]]];
        $f = Frame::new($cells);
        $this->assertSame(255, $f->cells[0][0][0]);
    }

    /**
     * SA-V1 -021: the geometry door. A buffer with lying dimensions — the
     * old public constructor's blind spot, where hasRgba() answered true
     * over geometry no byte consumer could honour.
     */
    public function testRgbaBytesMismatchedWithGeometryRefuse(): void
    {
        $this->expectException(\LogicException::class);
        Frame::new([[[0, 0, 0]]], 10, Frame::DISPOSAL_NONE, false, "\x00\x00\x00\xff\x00\x00\x00", 2, 1);
    }

    public function testRgbaBufferWithoutDimensionsRefuses(): void
    {
        $this->expectException(\LogicException::class);
        Frame::new([[[0, 0, 0]]], 10, Frame::DISPOSAL_NONE, false, "\x00\x00\x00\xff", 0, 0);
    }

    public function testDimensionsWithoutBufferRefuse(): void
    {
        $this->expectException(\LogicException::class);
        Frame::new([[[0, 0, 0]]], 10, Frame::DISPOSAL_NONE, false, null, 4, 4);
    }

    public function testCoherentRgbaTripleMints(): void
    {
        $f = Frame::new([[[0, 0, 0]]], 10, Frame::DISPOSAL_NONE, false, "\x00\x00\x00\xff", 1, 1);
        $this->assertTrue($f->hasRgba());
        $this->assertSame("\x00\x00\x00\xff", $f->rgbaBytes());
        $this->assertSame(1, $f->rgbaWidth);
        $this->assertSame(1, $f->rgbaHeight);
        // The @deprecated method reach (frozen sugar-image consumer) still
        // answers — removal is the filed rider, not this lane.
        $this->assertSame(1, $f->rgbaWidth());
        $this->assertSame(1, $f->rgbaHeight());
    }

    public function testBufferlessFrameMintsAtZeroGeometry(): void
    {
        $f = Frame::new([[[0, 0, 0]]]);
        $this->assertFalse($f->hasRgba());
        $this->assertSame(0, $f->rgbaWidth);
        $this->assertSame(0, $f->rgbaHeight);
    }
}

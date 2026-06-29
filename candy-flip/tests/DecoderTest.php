<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use PHPUnit\Framework\TestCase;

final class DecoderTest extends TestCase
{
    private string $gifPath;

    protected function setUp(): void
    {
        // Minimal 1x1 red pixel GIF87a
        $this->gifPath = sys_get_temp_dir() . '/test-' . uniqid() . '.gif';
        // GIF87a header: 6 bytes
        // Logical Screen Descriptor: 7 bytes
        // Global Color Table (3 colors = 9 bytes)
        // Image Descriptor: 10 bytes
        // LZW min code size: 1 byte
        // Image data (0x21 = GIF trailer): 2 bytes
        $gif = "\x47\x49\x46\x38\x37\x61"  // GIF87a header
             . "\x01\x00\x01\x00\x80\x00\x00" // LSD: 1x1, global color table, bg=0, par=0
             . "\x00\x00\x00"                  // GCT: color 0 = black
             . "\xff\x00\x00\x00\x00\x00\x00" // color 1 = red, color 2 = black
             . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00" // Image Descriptor
             . "\x02"                          // LZW min code size
             . "\x44\x00\x3b";                 // image data + GIF trailer
        file_put_contents($this->gifPath, $gif);
    }

    protected function tearDown(): void
    {
        if (isset($this->gifPath) && file_exists($this->gifPath)) {
            unlink($this->gifPath);
        }
    }

    /**
     * Regression: a GIF decodes to a PALETTE image, so the sampler must
     * resolve palette indices to real RGB (imagecolorsforindex) rather than
     * treating the index as a packed truecolor int. The old code did the
     * latter and returned (0,0,0) for every solid color / leaked the palette
     * index into the blue channel for gradients.
     *
     * @dataProvider solidColorProvider
     */
    public function testDecoderResolvesPaletteColorsToRgb(int $r, int $g, int $b): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $path = sys_get_temp_dir() . '/flip-color-' . uniqid() . '.gif';
        $im = imagecreatetruecolor(8, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        imagegif($im, $path);
        imagedestroy($im);

        try {
            $cell = Decoder::decode($path, 4, 2)[0]->cells[0][0] ?? null;
            $this->assertNotNull($cell, 'solid-color cell must not be null/transparent');
            // ext-gd's GIF palette round-trip is near-exact; allow a small delta.
            $this->assertEqualsWithDelta($r, $cell[0], 6, 'red channel');
            $this->assertEqualsWithDelta($g, $cell[1], 6, 'green channel');
            $this->assertEqualsWithDelta($b, $cell[2], 6, 'blue channel');
        } finally {
            unlink($path);
        }
    }

    /**
     * @return list<array{int,int,int}>
     */
    public static function solidColorProvider(): array
    {
        return [
            'red'    => [255, 0, 0],
            'green'  => [0, 255, 0],
            'blue'   => [0, 0, 255],
            'orange' => [200, 100, 50],
            'white'  => [255, 255, 255],
        ];
    }

    public function testDecodeReturnsFrames(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertNotEmpty($frames);
    }

    public function testDecodeReturnsCorrectDimensions(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $frames = Decoder::decode($this->gifPath, 2, 2);
        $this->assertInstanceOf(\SugarCraft\Flip\Frame::class, $frames[0]);
        $this->assertSame(2, $frames[0]->width());
        $this->assertSame(2, $frames[0]->height());
    }

    public function testDecodeThrowsForNonexistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Decoder::decode('/nonexistent/path/file.gif', 1, 1);
    }

    public function testDecodeThrowsForInvalidBytes(): void
    {
        $badPath = sys_get_temp_dir() . '/not-a-gif-' . uniqid();
        file_put_contents($badPath, 'NOT A GIF FILE');
        try {
            $this->expectException(\RuntimeException::class);
            Decoder::decode($badPath, 1, 1);
        } finally {
            unlink($badPath);
        }
    }

    public function testDecodeThrowsForTooShortBytes(): void
    {
        $badPath = sys_get_temp_dir() . '/short-' . uniqid();
        file_put_contents($badPath, 'GIF');
        try {
            $this->expectException(\RuntimeException::class);
            Decoder::decode($badPath, 1, 1);
        } finally {
            unlink($badPath);
        }
    }

    /**
     * Regression: zero or negative cell dimensions must throw rather than
     * allocating a zero-size buffer or looping infinitely.
     */
    public function testRejectsZeroCellGrid(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $this->expectException(\RuntimeException::class);
        Decoder::decode($this->gifPath, 0, 10);
    }

    public function testRejectsNegativeCellGrid(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $this->expectException(\RuntimeException::class);
        Decoder::decode($this->gifPath, -1, 10);
    }

    /**
     * Regression: an oversized cell grid product (cellsW * cellsH > MAX_CELLS)
     * must throw to prevent excessive memory allocation.
     */
    public function testRejectsOversizedCellGrid(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $this->expectException(\RuntimeException::class);
        // 500 * 500 = 250,000 which exceeds MAX_CELLS (100,000)
        Decoder::decode($this->gifPath, 500, 500);
    }

    /**
     * Regression: a real GD-encoded GIF whose LZW image data spans
     * multiple full-size (≥128-byte) sub-blocks used to decode to zero
     * frames, because findImageDataEnd() bailed on any sub-block length
     * ≥ 0x80 and truncated the stream so imagecreatefromstring() failed.
     * The tiny hand-built fixtures elsewhere have single small sub-blocks
     * and so never exercised this path.
     */
    public function testDecodeHandlesLargeLzwSubBlocks(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        // A 120x60 gradient yields several 254-byte LZW sub-blocks.
        $im = imagecreatetruecolor(120, 60);
        for ($y = 0; $y < 60; $y++) {
            for ($x = 0; $x < 120; $x++) {
                $col = imagecolorallocate($im, (int) (255 * $x / 120), (int) (255 * $y / 60), 128);
                imagesetpixel($im, $x, $y, $col);
            }
        }
        $path = sys_get_temp_dir() . '/large-' . uniqid() . '.gif';
        imagegif($im, $path);
        imagedestroy($im);

        try {
            $frames = Decoder::decode($path, 60, 18);
            $this->assertNotEmpty($frames, 'multi-sub-block GIF must decode to at least one frame');
            $this->assertSame(60, $frames[0]->width());
            $this->assertSame(18, $frames[0]->height());
        } finally {
            unlink($path);
        }
    }

    /**
     * Regression: a GIF whose extension sub-block length byte overruns EOF
     * must not cause PHP warnings or exceptions. The bounds check added to
     * the sub-block loops treats a truncated tail as end-of-data.
     */
    public function testDecodeHandlesTruncatedSubBlockOverrun(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        // Build a GIF where an extension sub-block length byte claims 255 bytes
        // but only a few bytes of payload follow before EOF.
        $buf = "GIF87a";
        $buf .= pack('v', 2); // width=2
        $buf .= pack('v', 2); // height=2
        $buf .= "\x80";       // GCT flag=1, GCT size=0 (2 entries)
        $buf .= "\x00";       // bg index
        $buf .= "\x00";       // par
        $buf .= "\x00\x00\x00"; // GCT: color 0 = black
        $buf .= "\xff\x00\x00"; // GCT: color 1 = red
        // Extension block (not GCE) with sub-block length=255 but only
        // 2 bytes follow before the GIF trailer (0x3B).
        $buf .= "\x21\xFF";   // extension introducer + label (fake)
        $buf .= "\xff";       // sub-block length claims 255 bytes
        $buf .= "\x00\x3b";   // only 2 bytes before trailer — overruns EOF
        // GIF should decode to empty frame list (no Image Descriptor found
        // before EOF) without throwing or emitting PHP warnings.
        $path = sys_get_temp_dir() . '/truncated-' . uniqid() . '.gif';
        file_put_contents($path, $buf);
        try {
            $frames = @Decoder::decode($path, 2, 2);
            // Should return 0 frames (no valid Image Descriptor found)
            $this->assertIsArray($frames);
        } finally {
            unlink($path);
        }
    }
}

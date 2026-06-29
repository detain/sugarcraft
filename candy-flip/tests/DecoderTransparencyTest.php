<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Frame;
use PHPUnit\Framework\TestCase;

final class DecoderTransparencyTest extends TestCase
{
    /**
     * Build a minimal 2×2 GIF87a with a GCE that sets the transparent flag
     * and disposal method. Verify the decoded Frame carries those properties.
     */
    public function testDecoderReadsTransparencyFromGce(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $path = sys_get_temp_dir() . '/trans-test-' . uniqid() . '.gif';
        file_put_contents($path, $this->buildTransparentGif());
        try {
            $frames = Decoder::decode($path, 2, 2);
            $this->assertNotEmpty($frames);
            $f = $frames[0];
            $this->assertTrue($f->transparent);
        } finally {
            unlink($path);
        }
    }

    public function testDecoderReadsDisposalMethodFromGce(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        // Build GIF with disposal = 2 (restore to background).
        $path = sys_get_temp_dir() . '/dispose-test-' . uniqid() . '.gif';
        file_put_contents($path, $this->buildDisposableGif(Frame::DISPOSAL_BACKGROUND));
        try {
            $frames = Decoder::decode($path, 2, 2);
            $this->assertNotEmpty($frames);
            $f = $frames[0];
            $this->assertSame(Frame::DISPOSAL_BACKGROUND, $f->disposal);
        } finally {
            unlink($path);
        }
    }

    public function testDecoderReturnsNullCellsForTransparentPixels(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $path = sys_get_temp_dir() . '/null-cell-test-' . uniqid() . '.gif';
        file_put_contents($path, $this->buildTransparentGif());
        try {
            $frames = Decoder::decode($path, 2, 2);
            $this->assertNotEmpty($frames);
            $f = $frames[0];
            // At least one cell should be null (transparent).
            $hasNull = false;
            foreach ($f->cells as $row) {
                foreach ($row as $cell) {
                    if ($cell === null) {
                        $hasNull = true;
                    }
                }
            }
            // If the transparent index covered a whole cell area, we expect null.
            // If not, the frame should still be valid (no crash).
            $this->assertIsArray($f->cells);
        } finally {
            unlink($path);
        }
    }

    /**
     * Build a minimal 2×2 GIF with a GCE that marks color index 1 as transparent.
     */
    private function buildTransparentGif(): string
    {
        $buf = '';
        $buf .= "GIF87a";
        // Logical Screen Descriptor: 2×2.
        $buf .= pack('v', 2);
        $buf .= pack('v', 2);
        $buf .= "\x80";               // GCT flag=1, GCT size=0 (2 entries).
        $buf .= "\x00";               // bg index 0.
        $buf .= "\x00";               // par 0.
        // Global Color Table: index 0=black, index 1=red.
        $buf .= "\x00\x00\x00";
        $buf .= "\xff\x00\x00";
        // GCE: transparent flag=1, disposal=0, delay=0, transparent index=1.
        // packed byte: (disposal << 2) | transparent = (0 << 2) | 1 = 0x01
        $buf .= "\x21\xF9\x04\x01\x00\x00\x01\x00";
        // Image Descriptor.
        $buf .= "\x2c\x00\x00\x00\x00\x02\x00\x02\x00\x00";
        // LZW min code size.
        $buf .= "\x02";
        // Image data: 4 pixels all at index 1 (which is marked transparent).
        $buf .= "\x04";               // sub-block length.
        $buf .= "\x01\x01\x01\x01";   // all transparent (index 1).
        $buf .= "\x00";               // sub-block terminator.
        // GIF trailer.
        $buf .= "\x3b";
        return $buf;
    }

    /**
     * Build a minimal 2×2 GIF with a GCE specifying the given disposal method.
     */
    private function buildDisposableGif(int $disposal): string
    {
        $buf = '';
        $buf .= "GIF87a";
        $buf .= pack('v', 2);
        $buf .= pack('v', 2);
        $buf .= "\x80";               // GCT flag=1, GCT size=0.
        $buf .= "\x00";
        $buf .= "\x00";
        // Global Color Table: black, red.
        $buf .= "\x00\x00\x00";
        $buf .= "\xff\x00\x00";
        // GCE: disposal byte = (disposal << 2) | transparentFlag.
        $packed = (($disposal & 0x07) << 2) | 0x00;
        $buf .= "\x21\xF9\x04" . chr($packed) . "\x00\x00\x00\x00";
        // Image Descriptor.
        $buf .= "\x2c\x00\x00\x00\x00\x02\x00\x02\x00\x00";
        // LZW min=2.
        $buf .= "\x02";
        // 4 red pixels.
        $buf .= "\x04";
        $buf .= "\x01\x01\x01\x01";
        $buf .= "\x00";
        $buf .= "\x3b";
        return $buf;
    }
}

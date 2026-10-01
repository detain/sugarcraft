<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Decoder;

/**
 * Cap-wiring regression tests (anti-silent-removal).
 *
 * The decoder defends itself with three documented limits: the output cell
 * grid ({@see Decoder::MAX_CELLS}), the source logical screen
 * ({@see Decoder::MAX_TOTAL_PIXELS}) and the frame count
 * ({@see Decoder::MAX_FRAMES}). Each is pinned twice here: the literal value
 * (so a silent widening/narrowing is a red test, not a surprise at runtime)
 * and at least one behavioral path where the cap actually fires at its limit.
 */
final class DecoderCapWiringTest extends TestCase
{
    private ?string $tmpPath = null;

    protected function tearDown(): void
    {
        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
            $this->tmpPath = null;
        }
    }

    public function testTheThreeDocumentedCapsArePinnedAtTheirValues(): void
    {
        $this->assertSame(100_000, Decoder::MAX_CELLS);
        $this->assertSame(2_000_000, Decoder::MAX_TOTAL_PIXELS);
        $this->assertSame(256, Decoder::MAX_FRAMES);
    }

    public function testScreenCapRejectsCraftedOversizedHeader(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        // 65535x65535 logical screen (the header's maximum), no frames, trailer.
        $gif = 'GIF89a' . "\xFF\xFF\xFF\xFF" . "\x00\x00\x00" . "\x3B";
        $this->tmpPath = sys_get_temp_dir() . '/screen-cap-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $this->expectException(\RuntimeException::class);
        // Derived from the constant, never hand-typed: renaming or resizing
        // MAX_TOTAL_PIXELS reddens this arm instead of silently re-accepting.
        $this->expectExceptionMessage(
            'candy-flip: GIF screen dimensions exceed maximum (' . Decoder::MAX_TOTAL_PIXELS . ' pixels)'
        );
        Decoder::decode($this->tmpPath, 4, 4);
    }

    public function testScreenCapBoundaryIsAcceptedNotRejected(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        // Exactly MAX_TOTAL_PIXELS (1000x2000): the cap is "> max", so the
        // boundary itself must sail through. Whether GD then yields [] or a
        // frame for this frameless file is irrelevant — only the screen
        // refusal would be a regression (kills an off-by-one `>=` mutation).
        $gif = 'GIF89a' . pack('v2', 1000, 2000) . "\x00\x00\x00" . "\x3B";
        $this->tmpPath = sys_get_temp_dir() . '/screen-edge-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        try {
            $frames = Decoder::decode($this->tmpPath, 4, 4);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('screen dimensions', $e->getMessage());
            return;
        }
        $this->assertIsArray($frames);
    }

    public function testGridCapMessageIsDerivedFromTheCellsConstant(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        $gif = 'GIF89a' . "\x01\x00\x01\x00\x80\x00\x00"
             . "\x00\x00\x00\xff\x00\x00\x00\x00\x00"
             . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
             . "\x02\x44\x00\x3b";
        $this->tmpPath = sys_get_temp_dir() . '/grid-cap-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'candy-flip: cell grid product exceeds maximum (' . Decoder::MAX_CELLS . ')'
        );
        // A square grid one cell past the cap (ceil(sqrt(MAX_CELLS)) ** 2 > MAX_CELLS).
        $side = (int) ceil(sqrt(Decoder::MAX_CELLS));
        Decoder::decode($this->tmpPath, $side, $side);
    }
}

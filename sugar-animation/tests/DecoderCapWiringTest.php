<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;

/**
 * Cap-wiring regression tests (anti-silent-removal).
 *
 * The decoder defends itself with documented limits: the output cell grid
 * ({@see Decoder::MAX_CELLS}), the source logical screen
 * ({@see Decoder::MAX_TOTAL_PIXELS}), the frame count
 * ({@see Decoder::MAX_FRAMES}) and the joint cell budget
 * ({@see Decoder::MAX_TOTAL_CELLS}, SA-D2 -003b/-017). Each is pinned twice
 * here: the literal value (so a silent widening/narrowing is a red test, not
 * a surprise at runtime) and at least one behavioral path where the cap
 * actually fires at its limit.
 */
final class DecoderCapWiringTest extends TestCase
{
    /** 4-entry GCT matching packed byte 0x81 (black, red, green, blue). */
    private const PALETTE = "\x00\x00\x00\xff\x00\x00\x00\xff\x00\x00\x00\xff";

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
            'sugar-animation: GIF screen dimensions exceed maximum (' . Decoder::MAX_TOTAL_PIXELS . ' pixels)'
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
            'sugar-animation: cell grid too large (maximum ' . Decoder::MAX_CELLS . ' cells)'
        );
        // A square grid one cell past the cap (ceil(sqrt(MAX_CELLS)) ** 2 > MAX_CELLS).
        $side = (int) ceil(sqrt(Decoder::MAX_CELLS));
        Decoder::decode($this->tmpPath, $side, $side);
    }

    public function testJointCellBudgetIsPinnedAtItsValue(): void
    {
        $this->assertSame(2_000_000, Decoder::MAX_TOTAL_CELLS);
    }

    /**
     * SA-D2 (-003b/-017): the per-dimension caps multiply into residency —
     * grid × frames beyond MAX_TOTAL_CELLS is refused at the EAGER phase,
     * before any canvas allocation. In-sync descriptor flood (1×1 screen,
     * 100 frames), grid 200×101 = 20,200 cells → product 2,020,000 > 2M.
     */
    public function testJointCellBudgetRejectsProductBeyondTheCap(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        $bytes = 'GIF89a' . "\x01\x00\x01\x00\x81\x00\x00" . self::PALETTE
            . str_repeat("\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x44\x00", 100)
            . "\x3B";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'sugar-animation: total cell budget exceeded (maximum ' . Decoder::MAX_TOTAL_CELLS . ' cells)'
        );
        iterator_to_array(Decoder::decodeBytes($bytes, 200, 101));
    }

    /**
     * Boundary arm (kills a >= mutation): exactly MAX_TOTAL_CELLS
     * (20,000 cells × 100 frames) must sail through the budget gate. Any
     * other typed refusal from the corrupt-but-in-sync payloads is fine —
     * only the budget naming is regression here.
     */
    public function testJointCellBudgetBoundaryIsAcceptedNotRejected(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        $bytes = 'GIF89a' . "\x01\x00\x01\x00\x81\x00\x00" . self::PALETTE
            . str_repeat("\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x44\x00", 100)
            . "\x3B";

        try {
            $frames = iterator_to_array(Decoder::decodeBytes($bytes, 200, 100));
            $this->assertIsArray($frames);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('total cell budget', $e->getMessage());
        }
    }

    /** SA-D2 (-002): the snapshot pre-scan answers only for DISPOSAL_PREVIOUS. */
    public function testStreamNeedsSnapshotAnswersOnlyForDisposalPrevious(): void
    {
        $this->assertFalse(Decoder::streamNeedsSnapshot([]));
        $this->assertFalse(Decoder::streamNeedsSnapshot([['disposal' => 0], ['disposal' => 1], ['disposal' => 2]]));
        $this->assertTrue(Decoder::streamNeedsSnapshot([['disposal' => 0], ['disposal' => 3]]));
    }
}

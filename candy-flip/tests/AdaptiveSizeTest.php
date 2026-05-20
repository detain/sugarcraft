<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for adaptive cell-size behaviour in Renderer.
 *
 * Note: withAdaptiveSize() requires a real TTY (STDOUT must be a terminal
 * for SizeIoctl::query() to succeed). Those tests skip gracefully when no TTY
 * is present, e.g. in CI pipelines.
 */
final class AdaptiveSizeTest extends TestCase
{
    public function testCreateReturnsUnconstrainedRenderer(): void
    {
        $r = Renderer::create();
        $f = new Frame([[[255, 0, 0]]]);

        // Without adaptive constraints, all cells are emitted.
        $out = $r->renderFrame($f);
        $this->assertStringContainsString("\033[48;2;255;0;0m", $out);
    }

    public function testRenderFrameClampsRowCount(): void
    {
        // Use reflection to construct with specific adaptive sizes.
        $r = $this->createAdaptiveRenderer(2, 100);
        $f = new Frame([
            [[255, 0, 0]],
            [[0, 255, 0]],
            [[0, 0, 255]], // should be clamped out
        ]);

        $out = $r->renderFrame($f);
        $this->assertStringContainsString("\033[48;2;255;0;0m", $out);
        $this->assertStringContainsString("\033[48;2;0;255;0m", $out);
        $this->assertStringNotContainsString("\033[48;2;0;0;255m", $out);
    }

    public function testRenderFrameClampsColumnCount(): void
    {
        $r = $this->createAdaptiveRenderer(100, 1);
        $f = new Frame([
            [[255, 0, 0], [0, 255, 0], [0, 0, 255]],
        ]);

        $out = $r->renderFrame($f);
        $this->assertStringContainsString("\033[48;2;255;0;0m", $out);
        $this->assertStringNotContainsString("\033[48;2;0;255;0m", $out);
        $this->assertStringNotContainsString("\033[48;2;0;0;255m", $out);
    }

    public function testRenderFrameWithBothRowAndColClamping(): void
    {
        $r = $this->createAdaptiveRenderer(2, 2);
        // Frame: 4 rows × 3 cols. With maxRows=2 and maxCols=2, only
        // row 0 (cols 0,1) and row 1 (cols 0,1) should appear.
        $f = new Frame([
            [[255, 0, 0], [0, 255, 0], [0, 0, 255]],
            [[64, 0, 0], [0, 64, 0], [0, 0, 64]],
            [[128, 0, 0], [0, 128, 0], [0, 0, 128]],
            [[32, 0, 0], [0, 32, 0], [0, 0, 32]],
        ]);

        $out = $r->renderFrame($f);
        // Row 0, col 0 and col 1 should appear (within both limits).
        $this->assertStringContainsString("\033[48;2;255;0;0m", $out); // row 0, col 0
        $this->assertStringContainsString("\033[48;2;0;255;0m", $out);  // row 0, col 1
        // Col 2 is beyond maxCols=2 — all three remaining rows' col-2 cells excluded.
        $this->assertStringNotContainsString("\033[48;2;0;0;255m", $out);  // row 0, col 2 (clamped)
        $this->assertStringNotContainsString("\033[48;2;0;0;64m", $out);   // row 1, col 2 (clamped)
        $this->assertStringNotContainsString("\033[48;2;0;0;128m", $out);  // row 2, col 2 (clamped)
        $this->assertStringNotContainsString("\033[48;2;0;0;32m", $out);  // row 3, col 2 (clamped)
        // Row 2 and row 3 are beyond maxRows=2 — all their cells excluded.
        $this->assertStringNotContainsString("\033[48;2;128;0;0m", $out);  // row 2, col 0 (clamped)
        $this->assertStringNotContainsString("\033[48;2;32;0;0m", $out);  // row 3, col 0 (clamped)
    }

    public function testStaticRenderStillWorks(): void
    {
        // Backward-compatibility: Renderer::render() still callable statically.
        $f = new Frame([[[0, 0, 0]]]);
        $out = Renderer::render($f);
        $this->assertStringContainsString("\033[48;2;0;0;0m", $out);
    }

    public function testWithAdaptiveSizeSkipsWhenNoTty(): void
    {
        if (\posix_isatty(STDOUT)) {
            $this->markTestSkipped('STDOUT is a TTY — would query real terminal size');
        }

        // When STDOUT is not a TTY, SizeIoctl::query throws.
        $this->expectException(\RuntimeException::class);
        Renderer::withAdaptiveSize();
    }

    /**
     * Factory: build a Renderer with specific adaptive dimensions.
     *
     * @param int<0, max> $rows
     * @param int<0, max> $cols
     */
    private function createAdaptiveRenderer(int $rows, int $cols): Renderer
    {
        return Renderer::withConstraints($rows, $cols);
    }
}

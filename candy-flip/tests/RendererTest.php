<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testSolidPresetEmitsBgEscape(): void
    {
        $f = new Frame([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255], [128, 128, 128]],
        ]);
        $out = Renderer::render($f, Renderer::PRESET_SOLID);
        $this->assertStringContainsString("\033[48;2;255;0;0m ", $out);
        $this->assertStringContainsString("\033[48;2;0;255;0m ", $out);
        $this->assertStringContainsString("\033[48;2;0;0;255m ", $out);
    }

    public function testDensityPresetPicksGlyphFromRamp(): void
    {
        // White cell → top of ramp (`@`); black → bottom (` `).
        $f = new Frame([
            [[0, 0, 0], [255, 255, 255]],
        ]);
        $out = Renderer::render($f, Renderer::PRESET_DENSITY);
        // luminance(white) ≈ 255 → '@'
        $this->assertStringContainsString('@', $out);
    }

    public function testRendererTerminatesEachLineWithReset(): void
    {
        $f = new Frame([
            [[0, 0, 0]],
            [[255, 255, 255]],
        ]);
        $out = Renderer::render($f, Renderer::PRESET_SOLID);
        $lines = explode("\n", $out);
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringEndsWith("\033[0m", $line);
        }
    }

    public function testFrameDimensionsExposed(): void
    {
        $f = new Frame([
            [[0, 0, 0], [0, 0, 0], [0, 0, 0]],
            [[0, 0, 0], [0, 0, 0], [0, 0, 0]],
        ]);
        $this->assertSame(3, $f->width());
        $this->assertSame(2, $f->height());
    }

    /**
     * Regression: adjacent cells with identical RGB should emit a single SGR
     * sequence followed by N glyphs, rather than N separate SGR + glyph pairs.
     * This validates the run-coalescing optimisation in renderFrame().
     */
    public function testCoalescesAdjacentRuns(): void
    {
        // 3 solid cells in a row, all the same red colour.
        $f = new Frame([[[255, 0, 0], [255, 0, 0], [255, 0, 0]]]);
        $r = Renderer::withConstraints(1, 3);
        $out = $r->renderFrame($f, Renderer::PRESET_SOLID);

        // The SGR for red appears exactly once (coalesced), not three times.
        $redSgr = "\033[48;2;255;0;0m";
        $this->assertSame(1, substr_count($out, $redSgr),
            'Same-color adjacent cells must share a single SGR sequence');

        // Each cell emits exactly one character (space) after the SGR.
        // Total line length: 1 SGR + 3 spaces + 1 reset = 5 escapes/chars groups.
        $this->assertEquals(3, strlen(preg_replace('/\033\[[0-9;]+m/', '', $out)));
    }

    /**
     * Transparent cells break colour runs — the SGR count must reset after a
     * null cell even if surrounding cells share the same colour.
     */
    public function testTransparentCellBreaksRun(): void
    {
        // Row: red, transparent, red (same colour on both sides of null).
        $f = new Frame([[[255, 0, 0], null, [255, 0, 0]]]);
        $r = Renderer::withConstraints(1, 3);
        $out = $r->renderFrame($f, Renderer::PRESET_SOLID);

        // Red SGR should appear exactly twice: before the first red and after
        // the transparent cell (which breaks the run).
        $redSgr = "\033[48;2;255;0;0m";
        $this->assertSame(2, substr_count($out, $redSgr),
            'Transparent cells must break the colour run');
    }
}

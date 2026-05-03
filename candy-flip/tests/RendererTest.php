<?php

declare(strict_types=1);

namespace CandyCore\Flip\Tests;

use CandyCore\Flip\Frame;
use CandyCore\Flip\Renderer;
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
}

<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Renderer;
use SugarCraft\Testing\Snapshot\Assertions;

/**
 * Golden-file snapshot tests for candy-flip ANSI frame rendering.
 *
 * Captures the byte-exact output of Renderer::renderFrame() to detect
 * regressions in terminal color/glyph rendering.
 *
 * @see Mirrors charmbracelet/gifterm frame rendering
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    /**
     * Test that a small solid-preset frame renders deterministic ANSI output.
     *
     * Uses density preset on a 3x3 RGB grid to produce a compact, readable
     * snapshot that pins the cell→glyph mapping and truecolor escapes.
     */
    public function testDensityPresetRendersAnsi(): void
    {
        // 3×3 grid: red corner, green center, blue corner, rest transparent.
        $cells = [
            [[255, 0, 0], [128, 0, 0], [64, 0, 0]],
            [[0, 255, 0], null, [0, 128, 0]],
            [[0, 0, 255], [0, 0, 128], [0, 0, 64]],
        ];
        $frame = new Frame($cells, delay: 10, disposal: Frame::DISPOSAL_NONE, transparent: true);

        // Constrain output to 3×3 so it doesn't depend on terminal size.
        $renderer = Renderer::withConstraints(3, 3);
        $output = $renderer->renderFrame($frame, Renderer::PRESET_DENSITY);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/density-3x3.golden',
            $output,
        );
    }
}

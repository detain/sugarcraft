<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer;

/**
 * Regression guard for the CRLF→LF join bug.
 *
 * The half-block / quarter-block renderers historically joined their output
 * rows with "\r\n". A stray carriage return is harmful in the TUI pipeline
 * (sugar-boxer / candy-core / phlix-console-client) which splits on "\n" and
 * positions every line itself:
 *
 *   - a consumer doing explode("\n", $poster) keeps a trailing "\r" on every
 *     row but the last, inflating the measured visible width by 1 (a 14-cell
 *     render measures 15), and
 *   - when the "\r" is finally emitted it snaps the terminal cursor to
 *     column 0 mid-frame, collapsing/misaligning multi-line block images.
 *
 * These tests lock in the fix: output is "\n"-delimited, contains no "\r"
 * byte, and every row's ANSI-stripped visible width equals the requested
 * cell width.
 */
final class BlockNewlineRegressionTest extends TestCase
{
    /**
     * Build a simple opaque RGB test image as an ImageSource.
     */
    private function makeImage(int $w, int $h): ImageSource
    {
        $gd = imagecreatetruecolor($w, $h);
        self::assertNotFalse($gd);

        // Paint a deterministic non-black gradient so every cell renders a
        // glyph + SGR codes (quarter-block treats near-black as "empty").
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = 40 + (($x * 211) % 200);
                $g = 40 + (($y * 151) % 200);
                $b = 40 + ((($x + $y) * 97) % 200);
                $color = imagecolorallocate($gd, $r, $g, $b);
                imagesetpixel($gd, $x, $y, $color);
            }
        }

        $src = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        return $src;
    }

    /**
     * Visible (printable) width of a row: strip ANSI SGR/control sequences,
     * then measure display columns.
     */
    private function visibleWidth(string $row): int
    {
        return mb_strwidth(Ansi::strip($row), 'UTF-8');
    }

    public function testHalfBlockOutputHasNoCarriageReturn(): void
    {
        $image = $this->makeImage(14, 18);
        $out = (new HalfBlockRenderer())->render($image, 14, 9);

        $this->assertStringNotContainsString("\r", $out);
    }

    public function testHalfBlockSplitsIntoExpectedRowCount(): void
    {
        $image = $this->makeImage(14, 18);
        $out = (new HalfBlockRenderer())->render($image, 14, 9);

        $rows = explode("\n", $out);
        $this->assertCount(9, $rows);
    }

    public function testHalfBlockEveryRowHasExactRequestedWidth(): void
    {
        $width = 14;
        $image = $this->makeImage($width, 18);
        $out = (new HalfBlockRenderer())->render($image, $width, 9);

        foreach (explode("\n", $out) as $i => $row) {
            $this->assertSame(
                $width,
                $this->visibleWidth($row),
                "half-block row {$i} visible width must equal requested width {$width}"
            );
        }
    }

    public function testQuarterBlockOutputHasNoCarriageReturn(): void
    {
        $image = $this->makeImage(28, 36);
        $out = (new QuarterBlockRenderer())->render($image, 14, 9);

        $this->assertStringNotContainsString("\r", $out);
    }

    public function testQuarterBlockSplitsIntoExpectedRowCount(): void
    {
        $image = $this->makeImage(28, 36);
        $out = (new QuarterBlockRenderer())->render($image, 14, 9);

        $rows = explode("\n", $out);
        $this->assertCount(9, $rows);
    }

    public function testQuarterBlockEveryRowHasExactRequestedWidth(): void
    {
        $width = 14;
        $image = $this->makeImage(28, 36);
        $out = (new QuarterBlockRenderer())->render($image, $width, 9);

        foreach (explode("\n", $out) as $i => $row) {
            $this->assertSame(
                $width,
                $this->visibleWidth($row),
                "quarter-block row {$i} visible width must equal requested width {$width}"
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;

/**
 * The per-line prefilter and the printable-ASCII run in ImageOverlay::resolve
 * (audit rank 17). Both must leave the output and the paint geometry exactly
 * as the per-codepoint walk did.
 */
final class ImageOverlayFastPathTest extends TestCase
{
    public function testLinesWithoutAnEscapeComeBackByteIdenticalEvenWithImages(): void
    {
        $frame = "plain ascii\ttab\nhéllo 中文 ✓\n";
        $images = [0 => new ImagePlacement('BLOB', 4, 1)];

        [$body, $paints] = ImageOverlay::resolve($frame, $images);

        $this->assertSame($frame, $body);
        $this->assertSame([], $paints);
    }

    public function testPaintColumnAfterAnAsciiRunCountsOneCellPerByte(): void
    {
        $frame = 'abcdefgh' . ImageOverlay::marker(0) . ' tail';
        $images = [0 => new ImagePlacement('BLOB', 4, 1)];

        [$body, $paints] = ImageOverlay::resolve($frame, $images);

        $this->assertSame('abcdefgh  tail', $body, 'the marker cell becomes one space');
        $this->assertCount(1, $paints);
        $this->assertSame(1, $paints[0]['row']);
        $this->assertSame(9, $paints[0]['col'], 'eight ASCII cells, so the image sits in column nine');
    }

    public function testWideCharactersBeforeAMarkerStillCountTwoCells(): void
    {
        $frame = "\u{4E2D}\u{6587}ab" . ImageOverlay::marker(0);
        $images = [0 => new ImagePlacement('BLOB', 4, 1)];

        [, $paints] = ImageOverlay::resolve($frame, $images);

        $this->assertCount(1, $paints);
        $this->assertSame(7, $paints[0]['col'], 'two wide glyphs (4 cells) plus ab (2 cells), so column seven');
    }

    public function testRowNumbersSurviveSkippedLines(): void
    {
        $frame = "no escapes here\nstill none\nrow three " . ImageOverlay::marker(0);
        $images = [0 => new ImagePlacement('BLOB', 4, 1)];

        [, $paints] = ImageOverlay::resolve($frame, $images);

        $this->assertCount(1, $paints);
        $this->assertSame(3, $paints[0]['row'], 'skipping an escape-free line keeps the row index');
    }
}

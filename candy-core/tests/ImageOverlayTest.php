<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Ansi;

final class ImageOverlayTest extends TestCase
{
    public function testMarkerIsASingleWidthOneCell(): void
    {
        $m = ImageOverlay::marker(0);
        self::assertSame(1, mb_strlen($m, 'UTF-8'));
        self::assertSame(0xE000, mb_ord($m, 'UTF-8'));
        self::assertSame(0xE001, mb_ord(ImageOverlay::marker(1), 'UTF-8'));
    }

    public function testMarkerRejectsOutOfRangeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageOverlay::marker(ImageOverlay::MAX_IMAGES);
    }

    public function testResolveReturnsFrameUnchangedWhenNoMarkers(): void
    {
        $frame = "hello\nworld";
        [$body, $paints] = ImageOverlay::resolve($frame, []);

        self::assertSame($frame, $body);
        self::assertSame([], $paints);
    }

    public function testResolveComputesRowAndColumnAndBlanksTheMarker(): void
    {
        // Marker sits at column 4 (0-based 3) of row 2.
        $frame = "first line\nabc" . ImageOverlay::marker(0) . "xyz";
        [$body, $paints] = ImageOverlay::resolve($frame, [0 => 'SIXELBYTES']);

        self::assertSame("first line\nabc xyz", $body, 'marker cell becomes a space');
        self::assertCount(1, $paints);
        self::assertSame(2, $paints[0]['row'], '1-based row');
        self::assertSame(4, $paints[0]['col'], '1-based col after the 3-char prefix');
        self::assertSame('SIXELBYTES', $paints[0]['bytes']);
    }

    public function testColumnCountingIgnoresAnsiEscapes(): void
    {
        // SGR colour before the marker must not shift the visible column.
        $frame = "\x1b[31mAB\x1b[0m" . ImageOverlay::marker(2);
        [, $paints] = ImageOverlay::resolve($frame, [2 => 'BLOB']);

        self::assertSame(3, $paints[0]['col'], 'AB = 2 cells, marker at col 3');
    }

    public function testColumnCountingHandlesWideCjkBeforeMarker(): void
    {
        // 日本語 = 3 CJK glyphs = 6 cells, so the marker lands at col 7.
        $frame = '日本語' . ImageOverlay::marker(0);
        [, $paints] = ImageOverlay::resolve($frame, [0 => 'X']);

        self::assertSame(7, $paints[0]['col']);
    }

    public function testMultipleMarkersAcrossRowsResolveIndependently(): void
    {
        $frame = ImageOverlay::marker(0) . "....  " . ImageOverlay::marker(1)
            . "\n\n" . "  " . ImageOverlay::marker(2);
        [$body, $paints] = ImageOverlay::resolve($frame, [0 => 'a', 1 => 'b', 2 => 'c']);

        self::assertCount(3, $paints);
        self::assertSame(['row' => 1, 'col' => 1, 'bytes' => 'a'], $paints[0]);
        // marker(0)=col1, then "....  " = 6 cells (cols 2-7), so marker(1)=col8.
        self::assertSame(['row' => 1, 'col' => 8, 'bytes' => 'b'], $paints[1]);
        self::assertSame(['row' => 3, 'col' => 3, 'bytes' => 'c'], $paints[2]);
        self::assertStringNotContainsString(ImageOverlay::marker(0), $body, 'all markers blanked');
    }

    public function testMarkerWithoutBlobIsBlankedButNotPainted(): void
    {
        $frame = 'x' . ImageOverlay::marker(5) . 'y';
        [$body, $paints] = ImageOverlay::resolve($frame, []); // no blob registered

        self::assertSame('x y', $body, 'stale marker never shows as tofu');
        self::assertSame([], $paints);
    }

    public function testPaintEmitsCursorPositionedBytesWrappedInSaveRestore(): void
    {
        $paints = [
            ['row' => 2, 'col' => 4, 'bytes' => 'AAA'],
            ['row' => 5, 'col' => 1, 'bytes' => 'BBB'],
        ];
        $out = ImageOverlay::paint($paints);

        $expected = Ansi::cursorSave()
            . Ansi::cursorTo(2, 4) . 'AAA'
            . Ansi::cursorTo(5, 1) . 'BBB'
            . Ansi::cursorRestore();
        self::assertSame($expected, $out);
    }

    public function testPaintOfEmptyListIsEmpty(): void
    {
        self::assertSame('', ImageOverlay::paint([]));
    }

    public function testSignatureIsStableForSamePaintsAndChangesWithPosition(): void
    {
        $a = [['row' => 1, 'col' => 1, 'bytes' => 'x']];
        $b = [['row' => 2, 'col' => 1, 'bytes' => 'x']];

        self::assertSame(ImageOverlay::signature($a), ImageOverlay::signature($a));
        self::assertNotSame(ImageOverlay::signature($a), ImageOverlay::signature($b));
    }

    public function testSignatureChangesWhenBlobChanges(): void
    {
        $a = [['row' => 1, 'col' => 1, 'bytes' => 'one']];
        $b = [['row' => 1, 'col' => 1, 'bytes' => 'two']];

        self::assertNotSame(ImageOverlay::signature($a), ImageOverlay::signature($b));
    }

    public function testMarkerBlockReservesAWidthByHeightBox(): void
    {
        $block = ImageOverlay::markerBlock(4, 6, 3);
        $rows = explode("\n", $block);

        self::assertCount(3, $rows);
        self::assertStringStartsWith(ImageOverlay::marker(4), $rows[0]);
        self::assertSame(6, mb_strlen($rows[0], 'UTF-8'), 'top row is width cells (marker + spaces)');
        self::assertSame(str_repeat(' ', 6), $rows[1], 'lower rows are blank');
    }

    public function testMarkerBlockResolvesToASinglePaintAtItsOrigin(): void
    {
        [$body, $paints] = ImageOverlay::resolve(ImageOverlay::markerBlock(0, 5, 2), [0 => 'BYTES']);

        self::assertSame(['row' => 1, 'col' => 1, 'bytes' => 'BYTES'], $paints[0]);
        self::assertStringNotContainsString(ImageOverlay::marker(0), $body);
    }

    public function testResolveRoundTripsAFullPosterStyleBlock(): void
    {
        // A 4-wide × 3-tall image box: marker top-left, the rest spaces, and a
        // styled card around it. Verifies the body stays a clean text grid.
        $marker = ImageOverlay::marker(0);
        $block = $marker . '   ' . "\n" . '    ' . "\n" . '    ';
        [$body, $paints] = ImageOverlay::resolve($block, [0 => 'SIX']);

        self::assertSame('    ' . "\n" . '    ' . "\n" . '    ', $body);
        self::assertSame(['row' => 1, 'col' => 1, 'bytes' => 'SIX'], $paints[0]);
    }
}

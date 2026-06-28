<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Core\Util\Ansi;

final class ImageOverlayTest extends TestCase
{
    /**
     * Build an id → ImagePlacement map from id => [bytes, w, h] (w/h default 1).
     *
     * @param array<int, array{0: string, 1?: int, 2?: int}> $spec
     * @return array<int, ImagePlacement>
     */
    private static function images(array $spec): array
    {
        $out = [];
        foreach ($spec as $id => $entry) {
            $out[$id] = new ImagePlacement($entry[0], $entry[1] ?? 1, $entry[2] ?? 1);
        }
        return $out;
    }

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

    public function testResolveComputesRowColumnFootprintAndBlanksTheMarker(): void
    {
        $frame = "first line\nabc" . ImageOverlay::marker(0) . "xyz";
        [$body, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['SIXELBYTES', 14, 9]]));

        self::assertSame("first line\nabc xyz", $body, 'marker cell becomes a space');
        self::assertCount(1, $paints);
        self::assertSame(['row' => 2, 'col' => 4, 'bytes' => 'SIXELBYTES', 'w' => 14, 'h' => 9], $paints[0]);
    }

    public function testColumnCountingIgnoresAnsiEscapes(): void
    {
        $frame = "\x1b[31mAB\x1b[0m" . ImageOverlay::marker(2);
        [, $paints] = ImageOverlay::resolve($frame, self::images([2 => ['BLOB']]));

        self::assertSame(3, $paints[0]['col'], 'AB = 2 cells, marker at col 3');
    }

    public function testColumnCountingHandlesWideCjkBeforeMarker(): void
    {
        $frame = '日本語' . ImageOverlay::marker(0);
        [, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['X']]));

        self::assertSame(7, $paints[0]['col']);
    }

    public function testMultipleMarkersAcrossRowsResolveIndependently(): void
    {
        $frame = ImageOverlay::marker(0) . "....  " . ImageOverlay::marker(1)
            . "\n\n" . "  " . ImageOverlay::marker(2);
        [$body, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['a'], 1 => ['b'], 2 => ['c']]));

        self::assertCount(3, $paints);
        self::assertSame([1, 'a'], [$paints[0]['row'], $paints[0]['bytes']]);
        self::assertSame(1, $paints[0]['col']);
        // marker(0)=col1, then "....  " = 6 cells (cols 2-7), so marker(1)=col8.
        self::assertSame([1, 8, 'b'], [$paints[1]['row'], $paints[1]['col'], $paints[1]['bytes']]);
        self::assertSame([3, 3, 'c'], [$paints[2]['row'], $paints[2]['col'], $paints[2]['bytes']]);
        self::assertStringNotContainsString(ImageOverlay::marker(0), $body, 'all markers blanked');
    }

    public function testMarkerWithoutPlacementIsBlankedButNotPainted(): void
    {
        $frame = 'x' . ImageOverlay::marker(5) . 'y';
        [$body, $paints] = ImageOverlay::resolve($frame, []);

        self::assertSame('x y', $body, 'stale marker never shows as tofu');
        self::assertSame([], $paints);
    }

    public function testPaintEmitsCursorPositionedBytesWrappedInSaveRestore(): void
    {
        $paints = [
            ['row' => 2, 'col' => 4, 'bytes' => 'AAA', 'w' => 3, 'h' => 2],
            ['row' => 5, 'col' => 1, 'bytes' => 'BBB', 'w' => 3, 'h' => 2],
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
        $a = [['row' => 1, 'col' => 1, 'bytes' => 'x', 'w' => 4, 'h' => 4]];
        $b = [['row' => 2, 'col' => 1, 'bytes' => 'x', 'w' => 4, 'h' => 4]];

        self::assertSame(ImageOverlay::signature($a), ImageOverlay::signature($a));
        self::assertNotSame(ImageOverlay::signature($a), ImageOverlay::signature($b));
    }

    public function testSignatureChangesWhenBlobOrFootprintChanges(): void
    {
        $a = [['row' => 1, 'col' => 1, 'bytes' => 'one', 'w' => 4, 'h' => 4]];
        $b = [['row' => 1, 'col' => 1, 'bytes' => 'two', 'w' => 4, 'h' => 4]];
        $c = [['row' => 1, 'col' => 1, 'bytes' => 'one', 'w' => 4, 'h' => 9]];

        self::assertNotSame(ImageOverlay::signature($a), ImageOverlay::signature($b));
        self::assertNotSame(ImageOverlay::signature($a), ImageOverlay::signature($c));
    }

    public function testCoveredRowsSpansEachImageTopThroughItsHeight(): void
    {
        $paints = [
            ['row' => 2, 'col' => 1, 'bytes' => 'a', 'w' => 14, 'h' => 3], // rows 2,3,4
            ['row' => 10, 'col' => 5, 'bytes' => 'b', 'w' => 14, 'h' => 2], // rows 10,11
        ];

        self::assertSame([2, 3, 4, 10, 11], array_keys(ImageOverlay::coveredRows($paints)));
    }

    public function testMarkerBlockResolvesToASinglePaintAtItsOrigin(): void
    {
        [$body, $paints] = ImageOverlay::resolve(ImageOverlay::markerBlock(0, 5, 2), self::images([0 => ['BYTES', 5, 2]]));

        self::assertSame(['row' => 1, 'col' => 1, 'bytes' => 'BYTES', 'w' => 5, 'h' => 2], $paints[0]);
        self::assertStringNotContainsString(ImageOverlay::marker(0), $body);
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
}

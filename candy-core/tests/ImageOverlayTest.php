<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

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

    /** The Private-Use cell a marker for $id ends in (its last codepoint). */
    private static function markerCell(int $id): int
    {
        return (int) mb_ord(mb_substr(ImageOverlay::marker($id), -1, 1, 'UTF-8'), 'UTF-8');
    }

    public function testMarkerIsASingleWidthOneCell(): void
    {
        $m = ImageOverlay::marker(0);
        // One visible cell: the authenticating escape is zero-width to every
        // ANSI-aware measure, so a marker row lays out as it always did.
        self::assertSame(1, Width::string($m));
        self::assertSame(' ', Ansi::strip(str_replace(mb_substr($m, -1, 1, 'UTF-8'), ' ', $m)));
        // Arena starts at U+E002: U+E000/U+E001 belong to the candy-mouse zone
        // sentinels (Sanitize::ZONE_SENTINEL_*), disjoint by the a32c4faae ruling.
        self::assertSame(0xE002, self::markerCell(0));
        self::assertSame(0xE003, self::markerCell(1));
        // And it tops out exactly at U+F8FF — never spilling into the CJK
        // Compatibility Ideographs block that follows the reserved arena.
        self::assertSame(0xF8FF, self::markerCell(ImageOverlay::MAX_IMAGES - 1));
    }

    public function testMarkerCannotSurviveTheUntrustedTextSanitizers(): void
    {
        // The property the whole fix rests on: the part of a marker that
        // authenticates it is an escape, and every untrusted-text policy
        // deletes ESC. What a hostile string can still carry is the bare cell.
        foreach ([Sanitize::untrusted(...), Sanitize::untrustedForMarkedFrames(...), Sanitize::untrustedForDisplay(...)] as $policy) {
            $cleaned = $policy('x' . ImageOverlay::marker(0) . 'y');
            [, $paints] = ImageOverlay::resolve($cleaned, self::images([0 => ['BLOB']]));
            self::assertSame([], $paints);
        }
    }

    public function testABarePrivateUseCodepointIsNeitherPaintedNorBlanked(): void
    {
        // Audit 15b-17: model or tool text carrying U+E002 + n used to paint a
        // second copy of on-screen image n where the text chose, and every
        // Private-Use glyph in the frame was blanked to a space.
        $forged = "\u{E002}";
        $powerline = "\u{E0B0}";
        $nerd = "\u{F115}";
        $pomicon = "\u{E003}"; // overlaps image id 1's cell
        $frame = ImageOverlay::marker(0) . "   \nanswer {$forged} sep {$powerline} dir {$nerd} {$pomicon} end";

        [$body, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['REAL', 4, 1], 1 => ['OTHER', 4, 1]]));

        self::assertCount(1, $paints, 'only the real marker paints');
        self::assertSame([1, 1, 'REAL'], [$paints[0]['row'], $paints[0]['col'], $paints[0]['bytes']]);
        self::assertSame("    \nanswer {$forged} sep {$powerline} dir {$nerd} {$pomicon} end", $body, 'glyphs survive byte-for-byte');
    }

    public function testBarePrivateUseTextWithNoImagesTakesTheFastPath(): void
    {
        $frame = "eza: \u{E0B0} \u{F115} \u{E002}";
        self::assertSame([$frame, []], ImageOverlay::resolve($frame, []));
    }

    public function testAnOrphanedMarkerEscapeIsDroppedWithoutAPaint(): void
    {
        // A layout pass that cut the marker cell off (Canvas/Veil re-emit the
        // escapes of a dropped region at the start of what is left) leaves the
        // escape before an unrelated cell. It must not paint there, even when
        // that cell is a space or a different id's cell, and must not reach
        // the terminal.
        $m0 = ImageOverlay::marker(0);
        $escape0 = substr($m0, 0, strlen($m0) - 3);
        $frame = "a{$escape0} b\n{$escape0}\u{E003}c\n{$escape0}";

        [$body, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['A'], 1 => ['B']]));

        self::assertSame([], $paints);
        self::assertSame("a b\n\u{E003}c\n", $body);
    }

    public function testMalformedMarkerEscapesAreNotMarkers(): void
    {
        $cell = "\u{E002}";
        foreach (["\x1b]candy-image;\x1b\\", "\x1b]candy-image;x0\x1b\\", "\x1b]candy-image;0\x07", "\x1b]candy-image;99999\x1b\\"] as $escape) {
            [, $paints] = ImageOverlay::resolve($escape . $cell, self::images([0 => ['A']]));
            self::assertSame([], $paints, bin2hex($escape));
        }
    }

    public function testOtherEscapesPassThroughResolve(): void
    {
        $link = Ansi::hyperlinkOpen('https://example.com');
        $frame = "\x1b[31m{$link}x\x1b[0m" . ImageOverlay::marker(0);
        [$body, $paints] = ImageOverlay::resolve($frame, self::images([0 => ['A']]));

        self::assertSame("\x1b[31m{$link}x\x1b[0m ", $body);
        self::assertSame(2, $paints[0]['col']);
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
        self::assertSame(6, Width::string($rows[0]), 'top row is width cells (marker + spaces)');
        self::assertSame(str_repeat(' ', 6), $rows[1], 'lower rows are blank');
    }
}

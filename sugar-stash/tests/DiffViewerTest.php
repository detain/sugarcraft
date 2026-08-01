<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\DiffViewer;
use PHPUnit\Framework\TestCase;

final class DiffViewerTest extends TestCase
{
    public function testFromRawDiffParsesHunkStarts(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '--- a/src/A.php',
            '+++ b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
            '@@ -5,3 +5,4 @@',
            '-line 5',
            '+line 5 modified',
        ];

        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);

        $this->assertSame('src/A.php', $dv->path);
        $this->assertSame($lines, $dv->lines);
        $this->assertSame(2, $dv->hunkCount());
        // First hunk starts at line index 3, second at line index 7
        $this->assertSame(3, $dv->hunkStarts[0]);
        $this->assertSame(7, $dv->hunkStarts[1]);
        // hunkCursor defaults to first hunk line
        $this->assertSame(3, $dv->hunkCursor);
    }

    public function testCurrentHunkLinesReturnsFirstHunk(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
            '@@ -5,3 +5,4 @@',
            '-line 5',
            '+line 5 modified',
        ];

        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        $hunk = $dv->currentHunkLines();

        $this->assertSame(['@@ -1,3 +1,4 @@', '-line 1', '+line 1 modified', ' context'], $hunk);
    }

    public function testCurrentHunkLinesReturnsSecondHunk(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
            '@@ -5,3 +5,4 @@',
            '-line 5',
            '+line 5 modified',
        ];

        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        $dv = $dv->withHunkCursor(1);
        $hunk = $dv->currentHunkLines();

        $this->assertSame(['@@ -5,3 +5,4 @@', '-line 5', '+line 5 modified'], $hunk);
    }

    public function testCurrentHunkPatchReturnsImplodedLines(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
        ];

        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        $patch = $dv->currentHunkPatch();

        $this->assertSame("diff --git a/src/A.php b/src/A.php\n@@ -1,3 +1,4 @@\n-line 1\n+line 1 modified\n context\n", $patch);
    }

    public function testWithHunkCursorClampsToValidRange(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            '@@ -5,3 +5,4 @@',
            '-line 5',
            '+line 5 modified',
        ];

        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);

        // Clamp up
        $dv2 = $dv->withHunkCursor(10);
        $this->assertSame($dv->hunkStarts[1], $dv2->hunkCursor);

        // Clamp down
        $dv3 = $dv2->withHunkCursor(-1);
        $this->assertSame($dv->hunkStarts[0], $dv3->hunkCursor);
    }

    public function testHunkCountZeroForNoHunks(): void
    {
        $dv = DiffViewer::fromRawDiff('src/A.php', ['diff --git a/src/A.php b/src/A.php']);
        $this->assertSame(0, $dv->hunkCount());
    }

    public function testCurrentHunkLinesOnNoHunksReturnsAllLines(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', 'generic diff content'];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        $this->assertSame($lines, $dv->currentHunkLines());
    }

    public function testFromRawDiffWithNoHunkMarkersPutsAllLinesInHeader(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', 'some diff content'];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        // No @@ markers → hunkStarts is empty, cursor is 0, header is all lines
        $this->assertSame(0, $dv->hunkCount());
        $this->assertSame(0, $dv->hunkCursor);
        $this->assertSame($lines, $dv->header);
        $this->assertSame($lines, $dv->currentHunkLines());
    }

    public function testWithHunkCursorOnEmptyHunkStartsReturnsZeroCursor(): void
    {
        $dv = DiffViewer::fromRawDiff('src/A.php', ['diff --git a/src/A.php b/src/A.php']);
        // hunkStarts is [] → withHunkCursor should still work and set cursor to 0
        $dv2 = $dv->withHunkCursor(5);
        $this->assertSame(0, $dv2->hunkCursor);
        $this->assertSame([], $dv2->hunkStarts);
    }

    public function testCurrentHunkPatchOnNoHunksReturnsImplodedLines(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', 'content'];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        $patch = $dv->currentHunkPatch();
        // No header section since all lines are header when no hunks
        $this->assertSame("diff --git a/src/A.php b/src/A.php\ncontent\n", $patch);
    }

    public function testFromRawDiffSeparatesHeaderFromHunkLines(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '--- a/src/A.php',
            '+++ b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
        ];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        // Header should be lines 0-2 (before first @@)
        $this->assertCount(3, $dv->header);
        $this->assertSame('diff --git a/src/A.php b/src/A.php', $dv->header[0]);
        $this->assertSame('--- a/src/A.php', $dv->header[1]);
        $this->assertSame('+++ b/src/A.php', $dv->header[2]);
        // Hunk starts at line index 3
        $this->assertSame(3, $dv->hunkStarts[0]);
    }
}

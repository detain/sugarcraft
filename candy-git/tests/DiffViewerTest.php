<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\DiffViewer;
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
        $dv = $dv->withHunkIndex(1);
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
        $dv2 = $dv->withHunkIndex(10);
        $this->assertSame($dv->hunkStarts[1], $dv2->hunkCursor);

        // Clamp down
        $dv3 = $dv2->withHunkIndex(-1);
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
        // hunkStarts is [] → withHunkIndex should still work and set cursor to 0
        $dv2 = $dv->withHunkIndex(5);
        $this->assertSame(0, $dv2->hunkCursor);
        $this->assertSame([], $dv2->hunkStarts);
    }

    public function testCurrentHunkPatchWithoutHunksIsEmpty(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', 'content'];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);
        // candy-git-073 contract: a header-only diff has no patchable
        // selection. The old behavior prepended the header to a line list
        // that already contained it, emitting every line twice into a blob
        // destined for git apply --cached and the undo payload. Byte-exact
        // assertion — the duplication cannot hide inside a contains-check.
        $this->assertSame('', $dv->currentHunkPatch());
    }

    public function testHunkIndexResolvesTheStoredLine(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '--- a/src/A.php',
            '+++ b/src/A.php',
            '@@ -1,2 +1,2 @@',
            '-a',
            '+b',
            '@@ -9,2 +9,2 @@',
            '-c',
            '+d',
        ];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);

        $this->assertSame(0, $dv->hunkIndex());
        $this->assertSame(3, $dv->currentHunkLine());

        $second = $dv->withHunkIndex(1);
        $this->assertSame(1, $second->hunkIndex());
        $this->assertSame(6, $second->currentHunkLine());
        // candy-git-059: the parameter is a HUNK INDEX, the stored field
        // stays the LINE index the windowing renders from.
        $this->assertSame(6, $second->hunkCursor);
    }

    public function testWithHunkIndexClampsBothEnds(): void
    {
        $lines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,1 +1,1 @@',
            '-a',
            '@@ -9,1 +9,1 @@',
            '-c',
        ];
        $dv = DiffViewer::fromRawDiff('src/A.php', $lines);

        $this->assertSame(1, $dv->withHunkIndex(42)->hunkIndex());
        $this->assertSame(0, $dv->withHunkIndex(-7)->hunkIndex());
        // PHP_INT_MAX — the End arm's spelling — lands on the last hunk.
        $this->assertSame(1, $dv->withHunkIndex(PHP_INT_MAX)->hunkIndex());
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

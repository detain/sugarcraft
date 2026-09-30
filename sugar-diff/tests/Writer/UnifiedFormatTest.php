<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Writer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\Writer\UnifiedFormat;

/**
 * Pins for the `diff -u` text writer: header shapes, the two GNU affordances
 * that are OFF by default to keep the ported engine byte-identical
 * (`/dev/null` empty-side labels, `\ No newline at end of file` markers), and
 * the prefix-free hunk-only mode.
 */
final class UnifiedFormatTest extends TestCase
{
    public function testWriteAndDiffUnifiedAgree(): void
    {
        $diff = Diff::compute('old', 'new');

        $this->assertSame($diff->unified('f.txt'), UnifiedFormat::write($diff, 'f.txt'));
    }

    public function testHeadersCarryNoTimestampAndLabelsArePrefixed(): void
    {
        $diff = Diff::compute("a\n", "b\n");

        $this->assertSame(
            "--- a/old.txt\n+++ b/new.txt\n@@ -1,1 +1,1 @@\n-a\n+b\n",
            $diff->unified('old.txt', 'new.txt'),
            'two-path mode mirrors `git diff` a/ b/ labels',
        );
        // One-path mode mirrors the ported unifiedDiff($path, ...).
        $this->assertStringStartsWith("--- a/same\n+++ b/same\n", $diff->unified('same'));
    }

    public function testEmptyDiffWritesNothing(): void
    {
        $this->assertSame('', UnifiedFormat::write(Diff::compute('same', 'same'), 'f'));
    }

    public function testDevNullLabelsAnEmptySideOnlyWhenAsked(): void
    {
        $added = Diff::compute('', "new\n", DiffOptions::new()->withDevNullOnEmptySide());
        $this->assertStringStartsWith("--- /dev/null\n+++ b/n.txt\n", $added->unified('n.txt'));

        $removed = Diff::compute("gone\n", '', DiffOptions::new()->withDevNullOnEmptySide());
        $this->assertStringStartsWith("--- a/g.txt\n+++ /dev/null\n", $removed->unified('g.txt'));

        // Default (faithful-port) polarity: real labels on both sides.
        $this->assertStringStartsWith(
            "--- a/n.txt\n+++ b/n.txt\n",
            Diff::compute('', "new\n")->unified('n.txt'),
        );
    }

    public function testDevNullDoesNotFireForNonEmptySides(): void
    {
        $diff = Diff::compute("a\n", "b\n", DiffOptions::new()->withDevNullOnEmptySide());

        $this->assertStringStartsWith("--- a/f\n+++ b/f\n", $diff->unified('f'));
    }

    public function testNoNewlineMarkerAnnotatesEveryUnterminatedEofRow(): void
    {
        // Both sides end WITHOUT a newline and both EOF lines change: GNU
        // repeats the annotation after each row.
        $options = DiffOptions::new()->withNoNewlineMarker();
        $diff = Diff::compute("a\nb\nc\ndel-me", "a\nb\nc\nadd-me", $options);

        $this->assertSame(
            "@@ -1,4 +1,4 @@\n"
            . " a\n b\n c\n"
            . "-del-me\n\\ No newline at end of file\n"
            . "+add-me\n\\ No newline at end of file\n",
            $diff->hunkText(),
        );
    }

    public function testNoNewlineMarkerFiresOnceForSharedContextEofRow(): void
    {
        // Old side runs out without a newline while the new side continues:
        // the shared context row sits at the old EOF, so it gets ONE
        // annotation (the fold emits per-row, not per-side).
        $options = DiffOptions::new()->withNoNewlineMarker();
        $diff = Diff::compute("one\ntwo", "zero\none\ntwo\n", $options);

        $this->assertSame(
            "@@ -1,2 +1,3 @@\n"
            . "+zero\n one\n two\n"
            . "\\ No newline at end of file\n",
            $diff->hunkText(),
        );
    }

    public function testMarkerOptionOffKeepsPortedOutputByteClean(): void
    {
        $diff = Diff::compute("a\ndel-me", "a\nadd-me");

        $this->assertStringNotContainsString('\\ No newline', $diff->unified('f'));
    }

    public function testHunksOnlyOmitsTheFileHeaders(): void
    {
        $diff = Diff::compute("a\n", "b\n");

        $this->assertSame("@@ -1,1 +1,1 @@\n-a\n+b\n", $diff->hunkText());
        $this->assertStringNotContainsString('---', $diff->hunkText());
    }

    public function testNoNewlineRowConstantMatchesTheEmittedShape(): void
    {
        $this->assertSame("\\ No newline at end of file\n", UnifiedFormat::NO_NEWLINE_ROW);
    }
}

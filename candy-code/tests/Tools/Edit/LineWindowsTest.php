<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\LineWindows;

final class LineWindowsTest extends TestCase
{
    private function trim(): \Closure
    {
        return static fn (string $l): string => trim($l);
    }

    public function testFindsWindowAndReportsSpanAndDetail(): void
    {
        $content = "a\n  b\n  c\nd\n";
        $found = LineWindows::find($content, "b\nc\n", "B\nC\n", $this->trim(), 'trimmed');

        self::assertCount(1, $found);
        self::assertSame("  b\n  c\n", $found[0]->matchedIn($content));
        // the old string was unindented, the file is indented by two: new is reindented
        self::assertSame("  B\n  C\n", $found[0]->newString);
        self::assertStringStartsWith('trimmed; new_string was re-indented', (string) $found[0]->detail);
    }

    public function testNoIndentChangeLeavesDetailAsTheBareLabel(): void
    {
        $content = "x\ny\n";
        $found = LineWindows::find($content, "x\n", "z\n", $this->trim(), 'label');

        self::assertSame('label', $found[0]->detail);
        self::assertSame("z\n", $found[0]->newString);
    }

    public function testEveryOccurrenceIsACandidate(): void
    {
        $content = "k\nm\nk\nm\nk\n";

        self::assertCount(2, LineWindows::find($content, "k\nm", "q\nq", $this->trim(), 'w'));
    }

    public function testNoMatchAndBlankNeedleYieldNothing(): void
    {
        self::assertSame([], LineWindows::find("a\nb\n", "zz\n", 'y', $this->trim(), 'w'));
        self::assertSame([], LineWindows::find("a\n\nb\n", "  \n", 'y', $this->trim(), 'w'));
    }

    public function testNeedleLongerThanContentYieldsNothing(): void
    {
        self::assertSame([], LineWindows::find("a", "a\nb\nc", 'y', $this->trim(), 'w'));
    }

    public function testCandidateDroppedWhenNewStringCannotBeReindented(): void
    {
        // A tab-indented needle against a space-indented file: new_string's bare line
        // does not start with the tab indent, so the match is refused rather than guessed.
        $content = "    foo\n";
        $found = LineWindows::find($content, "\tfoo\n", "bar\n", $this->trim(), 'w');

        self::assertSame([], $found);
    }

    public function testCrlfFileKeepsCrlfInReplacement(): void
    {
        $content = "a\r\nb\r\n";
        $found = LineWindows::find($content, "a\nb\n", "x\ny\n", $this->trim(), 'w');

        self::assertCount(1, $found);
        self::assertSame("x\r\ny\r\n", $found[0]->newString);
    }
}

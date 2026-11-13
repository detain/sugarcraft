<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\PatchHunk;

final class PatchHunkTest extends TestCase
{
    public function testOldAndNewTextJoinLinesWithTrailingNewline(): void
    {
        $h = new PatchHunk(['class A'], ['a', 'b'], ['a', 'c', 'd']);

        self::assertSame("a\nb\n", $h->oldText());
        self::assertSame("a\nc\nd\n", $h->newText());
    }

    public function testEmptySidesAreEmptyStrings(): void
    {
        $h = new PatchHunk([], [], []);

        self::assertSame('', $h->oldText());
        self::assertSame('', $h->newText());
    }

    public function testSingleEmptyLineIsOneNewlineNotEmpty(): void
    {
        self::assertSame("\n", (new PatchHunk([], [''], []))->oldText());
    }

    public function testEndOfFileDefaultsFalseAndCarriesAnchors(): void
    {
        $h = new PatchHunk(['one', 'two'], [], []);
        self::assertFalse($h->endOfFile);
        self::assertSame(['one', 'two'], $h->anchors);
        self::assertTrue((new PatchHunk([], [], [], true))->endOfFile);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\Lines;
use SugarCraft\Overlay\Veil;

final class LinesTest extends TestCase
{
    public function testSplitDropsTheEmptyTailOfAFinalNewline(): void
    {
        $this->assertSame(['a', 'b'], Lines::split("a\nb\n"));
        $this->assertSame(['a', 'b'], Lines::split("a\nb"));
    }

    public function testSplitKeepsInteriorBlankLines(): void
    {
        $this->assertSame(['a', '', 'b'], Lines::split("a\n\nb"));
    }

    public function testEmptyStringHasNoLines(): void
    {
        $this->assertSame([], Lines::split(''));
    }

    public function testVeilSplitLinesUsesTheSameRule(): void
    {
        foreach (["a\nb\n", "x", '', "\n\n", "a\n\nb\n"] as $text) {
            $this->assertSame(Lines::split($text), Veil::new()->splitLines($text));
        }
    }
}

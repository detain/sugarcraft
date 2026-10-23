<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\DatePicker\DatePicker;

/**
 * The display-width table is the canonical SugarCraft\Core\Util\Width
 * (Unicode 16.0 East_Asian_Width + Emoji_Presentation). DatePicker used to
 * carry a hand-rolled fork that scored modern emoji as 1 cell and shifted
 * later cells right on zero-width clusters (docs-fix-1023 E2 / r9x family).
 * These pins lock the delegation in from both sides: behavior + source.
 */
final class CanonicalWidthTest extends TestCase
{
    private function place(Buffer $buf, int $col, int $row, string $s): Buffer
    {
        $method = new \ReflectionMethod(DatePicker::class, 'placeStringAt');
        $method->setAccessible(true);

        return $method->invoke(new DatePicker(new \DateTimeImmutable('2026-05-01')), $buf, $col, $row, $s, null);
    }

    public function testZeroWidthClusterDoesNotConsumeAColumn(): void
    {
        $buf = $this->place(Buffer::new(21, 1), 0, 0, "A\u{200B}B");

        $this->assertSame('A', $buf->cellAt(0, 0)->rune());
        $this->assertSame('B', $buf->cellAt(1, 0)->rune(),
            'Zero-width cluster must not push the next cell right');
        $this->assertSame(' ', $buf->cellAt(2, 0)->rune(),
            'Cell after the string stays a buffer default');
    }

    public function testEmojiPresentationScoresTwoCellsViaCanonicalTable(): void
    {
        // U+1F300 CYCLONE has Emoji_Presentation=Yes; the stale fork scored
        // it 1, so the following 'X' landed in the emoji's trailing cell.
        $buf = $this->place(Buffer::new(21, 1), 0, 0, "\u{1F300}X");

        $this->assertSame("\u{1F300}", $buf->cellAt(0, 0)->rune());
        $this->assertSame(2, $buf->cellAt(0, 0)->width());
        $this->assertSame('', $buf->cellAt(1, 0)->rune(),
            'Wide cell must be followed by a continuation cell');
        $this->assertSame('X', $buf->cellAt(2, 0)->rune());
    }

    public function testWideCjkStillScoresTwoCells(): void
    {
        $buf = $this->place(Buffer::new(21, 1), 0, 0, "\u{4E00}X");

        $this->assertSame(2, $buf->cellAt(0, 0)->width());
        $this->assertSame('X', $buf->cellAt(2, 0)->rune());
    }

    public function testGraphemeWidthDelegatesAndForkTableIsGone(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/DatePicker.php');

        $this->assertStringContainsString('return Width::of($g);', $src,
            'graphemeWidth() must delegate to the canonical Width table');
        $this->assertStringNotContainsString('0x1100', $src,
            'The hand-rolled wide-range fork must not return');
        $this->assertMatchesRegularExpression('/use SugarCraft\\\\Core\\\\Util\\\\Width;/', $src);
    }
}

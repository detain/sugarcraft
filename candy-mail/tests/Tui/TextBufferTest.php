<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Tui\TextBuffer;

final class TextBufferTest extends TestCase
{
    public function testNewPlacesTheCursorAtTheEndAndExposesValueLengthEmpty(): void
    {
        $b = TextBuffer::new('héllo');
        $this->assertSame('héllo', $b->value());
        $this->assertSame(5, $b->cursor);
        $this->assertSame(5, $b->length());
        $this->assertFalse($b->isEmpty());
        $this->assertTrue(TextBuffer::new()->isEmpty());
    }

    public function testInsertEditsWholeCodePointsAtTheCursor(): void
    {
        $b = TextBuffer::new('ab')->left()->insert('日');
        $this->assertSame('a日b', $b->value());
        $this->assertSame(2, $b->cursor);
    }

    public function testInsertStripsControlsAndFoldsNewlinesWhenSingleLine(): void
    {
        $b = TextBuffer::new()->insert("a\x1b[31mb\r\nc\x07\x7f\xC2\x9b");
        $this->assertSame('a[31mb c', $b->value());
        $this->assertSame($b, $b->insert("\x1b"), 'an all-control insert is a no-op');
    }

    public function testMultilineKeepsNewlinesButNotOtherControls(): void
    {
        $b = TextBuffer::new('', true)->insert("x\r\ny\ttab\x00");
        $this->assertSame("x\ny" . 'tab', $b->value());
    }

    public function testBackspaceAndDeleteAndBoundaries(): void
    {
        $b = TextBuffer::new('abc');
        $this->assertSame('ab', $b->backspace()->value());
        $this->assertSame('bc', $b->home()->delete()->value());
        $this->assertSame(0, $b->home()->backspace()->cursor);
        $this->assertSame('abc', $b->home()->backspace()->value());
        $this->assertSame('abc', $b->delete()->value(), 'delete at the end is a no-op');
    }

    public function testLeftRightHomeEnd(): void
    {
        $b = TextBuffer::new('abc');
        $this->assertSame(2, $b->left()->cursor);
        $this->assertSame(0, $b->home()->left()->cursor);
        $this->assertSame(3, $b->right()->cursor);
        $this->assertSame(0, $b->home()->cursor);
        $this->assertSame(3, $b->home()->end()->cursor);
    }

    public function testUpDownKeepTheColumnAndClampOnShortLines(): void
    {
        $b = TextBuffer::new("abcd\nx\nefgh", true); // cursor at end of line 3, col 4
        $up = $b->up();
        $this->assertSame(6, $up->cursor, 'clamped to the end of the short line');
        $this->assertSame(1, $up->up()->cursor, 'col 1 of line 1');
        $this->assertTrue($up->up()->onFirstLine());
        $this->assertSame(1, $up->up()->up()->cursor);
        $this->assertSame(6, $up->up()->down()->cursor);
        $this->assertTrue($b->onLastLine());
        $this->assertSame($b->cursor, $b->down()->cursor);
        $this->assertFalse($up->onLastLine());
    }

    public function testClearKeepsTheMode(): void
    {
        $c = TextBuffer::new('abc', true)->clear();
        $this->assertTrue($c->isEmpty());
        $this->assertTrue($c->multiline);
    }

    public function testLayoutReportsLinesAndTheCursorCell(): void
    {
        $l = TextBuffer::new("ab\ncd", true)->left()->layout();
        $this->assertSame([['a', 'b'], ['c', 'd']], $l['lines']);
        $this->assertSame(1, $l['row']);
        $this->assertSame(1, $l['col']);
        $end = TextBuffer::new('xy')->layout();
        $this->assertSame([0, 2], [$end['row'], $end['col']]);
    }
}

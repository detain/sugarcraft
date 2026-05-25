<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Note;
use PHPUnit\Framework\TestCase;

final class NoteTest extends TestCase
{
    public function testRendersTitleAndDescription(): void
    {
        $n = Note::new('intro')->withTitle('Welcome')->withDescription('A short note.');
        $this->assertSame("Welcome\nA short note.", $n->view());
    }

    public function testIsSkippable(): void
    {
        $this->assertTrue(Note::new('x')->skippable());
    }

    public function testKeyAndValue(): void
    {
        $n = Note::new('intro');
        $this->assertSame('intro', $n->key());
        $this->assertNull($n->value());
    }

    public function testKeysIgnored(): void
    {
        [$n, ] = Note::new('x')->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $n->getTitle());
    }

    public function testWithNextRendersButtonAndIsNotSkippable(): void
    {
        $n = Note::new('intro')
            ->withTitle('Welcome')
            ->withNext()
            ->withNextLabel('Continue');
        $this->assertFalse($n->skippable());
        $this->assertTrue($n->isNext());
        $this->assertSame('Continue', $n->getNextLabel());
        $rendered = $n->view();
        $this->assertStringContainsString('[ Continue ]', $rendered);
    }

    public function testWithHeightPadsToFixedRowCount(): void
    {
        $n = Note::new('intro')->withTitle('A')->withDescription('B')->withHeight(5);
        $this->assertSame(5, $n->getHeight());
        $rows = explode("\n", $n->view());
        $this->assertCount(5, $rows);
    }

    public function testFocusedNoteRendersCursorMarker(): void
    {
        [$n, ] = Note::new('intro')->withTitle('Hi')->withNext()->focus();
        $this->assertTrue($n->isFocused());
        $this->assertStringContainsString('> [ Next ]', $n->view());
    }
}

<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Note;
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
}

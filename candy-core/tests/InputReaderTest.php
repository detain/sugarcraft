<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\InputReader;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class InputReaderTest extends TestCase
{
    public function testPrintableAscii(): void
    {
        $r = new InputReader();
        $msgs = $r->parse('abc');
        $this->assertCount(3, $msgs);
        foreach (['a', 'b', 'c'] as $i => $rune) {
            $this->assertInstanceOf(KeyMsg::class, $msgs[$i]);
            $this->assertSame(KeyType::Char, $msgs[$i]->type);
            $this->assertSame($rune, $msgs[$i]->rune);
            $this->assertFalse($msgs[$i]->ctrl);
            $this->assertFalse($msgs[$i]->alt);
        }
    }

    public function testCtrlC(): void
    {
        $msgs = (new InputReader())->parse("\x03");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('c', $msgs[0]->rune);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertSame('ctrl+c', $msgs[0]->string());
    }

    public function testTabEnterBackspaceSpace(): void
    {
        $msgs = (new InputReader())->parse("\t\r\x7f ");
        $this->assertSame(KeyType::Tab,       $msgs[0]->type);
        $this->assertSame(KeyType::Enter,     $msgs[1]->type);
        $this->assertSame(KeyType::Backspace, $msgs[2]->type);
        $this->assertSame(KeyType::Space,     $msgs[3]->type);
    }

    public function testArrowKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[A\x1b[B\x1b[C\x1b[D");
        $this->assertCount(4, $msgs);
        $this->assertSame(KeyType::Up,    $msgs[0]->type);
        $this->assertSame(KeyType::Down,  $msgs[1]->type);
        $this->assertSame(KeyType::Right, $msgs[2]->type);
        $this->assertSame(KeyType::Left,  $msgs[3]->type);
    }

    public function testHomeEndDeletePageKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[H\x1b[F\x1b[3~\x1b[5~\x1b[6~");
        $this->assertSame(KeyType::Home,     $msgs[0]->type);
        $this->assertSame(KeyType::End,      $msgs[1]->type);
        $this->assertSame(KeyType::Delete,   $msgs[2]->type);
        $this->assertSame(KeyType::PageUp,   $msgs[3]->type);
        $this->assertSame(KeyType::PageDown, $msgs[4]->type);
    }

    public function testAltPrefixedKey(): void
    {
        $msgs = (new InputReader())->parse("\x1ba");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('a',           $msgs[0]->rune);
        $this->assertTrue($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
        $this->assertSame('alt+a', $msgs[0]->string());
    }

    public function testBareEscapeIsBufferedThenFlushed(): void
    {
        $r = new InputReader();
        // Single ESC alone is ambiguous (could be the start of a sequence),
        // so it's buffered.
        $this->assertSame([], $r->parse("\x1b"));

        $flushed = $r->flushPending();
        $this->assertInstanceOf(KeyMsg::class, $flushed);
        $this->assertSame(KeyType::Escape, $flushed->type);
    }

    public function testSplitCsiAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b"));
        $this->assertSame([], $r->parse('['));
        $msgs = $r->parse('A');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Up, $msgs[0]->type);
    }

    public function testMixedStream(): void
    {
        $r = new InputReader();
        $msgs = $r->parse("hi\x1b[Aq");
        $this->assertCount(4, $msgs);
        $this->assertSame('h', $msgs[0]->rune);
        $this->assertSame('i', $msgs[1]->rune);
        $this->assertSame(KeyType::Up, $msgs[2]->type);
        $this->assertSame('q', $msgs[3]->rune);
    }

    public function testKeyMsgString(): void
    {
        $this->assertSame('a',        (new KeyMsg(KeyType::Char, 'a'))->string());
        $this->assertSame('ctrl+a',   (new KeyMsg(KeyType::Char, 'a', ctrl: true))->string());
        $this->assertSame('alt+a',    (new KeyMsg(KeyType::Char, 'a', alt: true))->string());
        $this->assertSame('up',       (new KeyMsg(KeyType::Up))->string());
        $this->assertSame('ctrl+alt+a', (new KeyMsg(KeyType::Char, 'a', alt: true, ctrl: true))->string());
    }
}

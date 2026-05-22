<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Parser;
use SugarCraft\Core\Util\Token;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testPlainText(): void
    {
        $tokens = (new Parser())->parse('hello');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('hello', $tokens[0]->data);
    }

    public function testControlBytes(): void
    {
        $tokens = (new Parser())->parse("a\nb\tc");
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('a', $tokens[0]->data);
        $this->assertSame(Token::CONTROL, $tokens[1]->type);
        $this->assertSame("\n", $tokens[1]->data);
        $this->assertSame('b', $tokens[2]->data);
        $this->assertSame(Token::CONTROL, $tokens[3]->type);
        $this->assertSame("\t", $tokens[3]->data);
        $this->assertSame('c', $tokens[4]->data);
    }

    public function testCsiBasic(): void
    {
        $tokens = (new Parser())->parse("\x1b[31m");
        $this->assertCount(1, $tokens);
        $t = $tokens[0];
        $this->assertSame(Token::CSI, $t->type);
        $this->assertSame('', $t->intermediate);
        $this->assertSame('31', $t->params);
        $this->assertSame('m', $t->final);
    }

    public function testCsiWithPrivateMarker(): void
    {
        $tokens = (new Parser())->parse("\x1b[?2026h");
        $this->assertCount(1, $tokens);
        $t = $tokens[0];
        $this->assertSame(Token::CSI, $t->type);
        $this->assertSame('?', $t->intermediate);
        $this->assertSame('2026', $t->params);
        $this->assertSame('h', $t->final);
    }

    public function testCsiWithMultipleParams(): void
    {
        $tokens = (new Parser())->parse("\x1b[1;2;3H");
        $this->assertSame('1;2;3', $tokens[0]->params);
        $this->assertSame([1, 2, 3], $tokens[0]->paramInts());
    }

    public function testCsiWithEmptyParam(): void
    {
        $tokens = (new Parser())->parse("\x1b[;5H");
        $this->assertSame([0, 5], $tokens[0]->paramInts());
    }

    public function testCsiWithIntermediate(): void
    {
        // DECRQM private mode 2026: "CSI ?2026$p"
        $tokens = (new Parser())->parse("\x1b[?2026\$p");
        $this->assertSame('?$', $tokens[0]->intermediate);
        $this->assertSame('2026', $tokens[0]->params);
        $this->assertSame('p', $tokens[0]->final);
    }

    public function testOscWithBel(): void
    {
        $tokens = (new Parser())->parse("\x1b]0;hello\x07");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('0;hello', $tokens[0]->data);
    }

    public function testOscWithSt(): void
    {
        $tokens = (new Parser())->parse("\x1b]52;c;dGVzdA==\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('52;c;dGVzdA==', $tokens[0]->data);
    }

    public function testDcs(): void
    {
        // XTVERSION reply: ESC P > | xterm(367) ESC \
        $tokens = (new Parser())->parse("\x1bP>|xterm(367)\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::DCS, $tokens[0]->type);
        $this->assertSame('>|xterm(367)', $tokens[0]->data);
    }

    public function testApc(): void
    {
        // CandyZone marker: ESC _ candyzone:S:foo ESC \
        $tokens = (new Parser())->parse("\x1b_candyzone:S:foo\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::APC, $tokens[0]->type);
        $this->assertSame('candyzone:S:foo', $tokens[0]->data);
    }

    public function testShortEscSequence(): void
    {
        $tokens = (new Parser())->parse("\x1b7");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::ESC, $tokens[0]->type);
        $this->assertSame('7', $tokens[0]->data);
    }

    public function testMixedStream(): void
    {
        $stream = "hello\x1b[31mworld\x1b[0m\n";
        $tokens = (new Parser())->parse($stream);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('hello', $tokens[0]->data);
        $this->assertSame(Token::CSI, $tokens[1]->type);
        $this->assertSame('m', $tokens[1]->final);
        $this->assertSame(Token::TEXT, $tokens[2]->type);
        $this->assertSame('world', $tokens[2]->data);
        $this->assertSame(Token::CSI, $tokens[3]->type);
        $this->assertSame(Token::CONTROL, $tokens[4]->type);
    }

    public function testIncompleteCsiBuffersUntilNextCall(): void
    {
        $p = new Parser();
        $tokens = $p->parse("a\x1b[3");
        $this->assertCount(1, $tokens);
        $this->assertSame('a', $tokens[0]->data);

        $tokens = $p->parse('1m');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('31', $tokens[0]->params);
        $this->assertSame('m', $tokens[0]->final);
    }

    public function testIncompleteOscBuffers(): void
    {
        $p = new Parser();
        $this->assertSame([], $p->parse("\x1b]0;tit"));
        $tokens = $p->parse("le\x07after");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('0;title', $tokens[0]->data);
        $this->assertSame('after', $tokens[1]->data);
    }

    public function testFlushReturnsBufferedAsText(): void
    {
        $p = new Parser();
        $this->assertSame([], $p->parse("\x1b[31"));
        $flushed = $p->flush();
        $this->assertCount(1, $flushed);
        $this->assertSame(Token::TEXT, $flushed[0]->type);
        $this->assertSame("\x1b[31", $flushed[0]->data);
    }
}

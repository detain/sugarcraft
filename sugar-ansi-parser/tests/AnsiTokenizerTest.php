<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\AnsiTokenizer;
use SugarCraft\AnsiParser\Token;

/**
 * Behavior of the flat stream tokenizer. Cases port sugar-core's Util\Parser
 * suite (the copy this class replaces in P1-14b, which deletes Util\Parser), and
 * add the edges that suite left unpinned (DEL, 8-bit C1 bytes, invalid CSI
 * finals, the flush contract, the held-bytes cap).
 */
final class AnsiTokenizerTest extends TestCase
{
    public function testNewReturnsAFreshInstance(): void
    {
        $this->assertNotSame(AnsiTokenizer::new(), AnsiTokenizer::new());
    }

    public function testPlainText(): void
    {
        $tokens = AnsiTokenizer::new()->parse('hello');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('hello', $tokens[0]->data);
    }

    public function testEmptyChunkYieldsNoTokens(): void
    {
        $this->assertSame([], AnsiTokenizer::new()->parse(''));
    }

    public function testControlBytesAreEmittedIndividually(): void
    {
        $tokens = AnsiTokenizer::new()->parse("a\nb\tc");
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('a', $tokens[0]->data);
        $this->assertSame(Token::CONTROL, $tokens[1]->type);
        $this->assertSame("\n", $tokens[1]->data);
        $this->assertSame('b', $tokens[2]->data);
        $this->assertSame(Token::CONTROL, $tokens[3]->type);
        $this->assertSame("\t", $tokens[3]->data);
        $this->assertSame('c', $tokens[4]->data);
    }

    public function testDelIsAControlToken(): void
    {
        $tokens = AnsiTokenizer::new()->parse("x\x7fy");
        $this->assertSame(Token::CONTROL, $tokens[1]->type);
        $this->assertSame("\x7f", $tokens[1]->data);
    }

    public function testEightBitC1BytesAreTextNotSequences(): void
    {
        // 0x9B is the 8-bit CSI introducer; only the 7-bit ESC [ opens a sequence here.
        $tokens = AnsiTokenizer::new()->parse("\x9b31m");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame("\x9b31m", $tokens[0]->data);
    }

    public function testCsiBasic(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b[31m");
        $this->assertCount(1, $tokens);
        $t = $tokens[0];
        $this->assertSame(Token::CSI, $t->type);
        $this->assertSame('', $t->intermediate);
        $this->assertSame('31', $t->params);
        $this->assertSame('m', $t->final);
    }

    public function testCsiWithPrivateMarker(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b[?2026h");
        $this->assertCount(1, $tokens);
        $t = $tokens[0];
        $this->assertSame(Token::CSI, $t->type);
        $this->assertSame('?', $t->intermediate);
        $this->assertSame('2026', $t->params);
        $this->assertSame('h', $t->final);
    }

    public function testCsiWithMultipleParams(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b[1;2;3H");
        $this->assertSame('1;2;3', $tokens[0]->params);
        $this->assertSame([1, 2, 3], $tokens[0]->paramInts());
    }

    public function testCsiWithEmptyParam(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b[;5H");
        $this->assertSame([0, 5], $tokens[0]->paramInts());
    }

    public function testCsiWithIntermediate(): void
    {
        // DECRQM private mode 2026: "CSI ?2026$p"
        $tokens = AnsiTokenizer::new()->parse("\x1b[?2026\$p");
        $this->assertSame('?$', $tokens[0]->intermediate);
        $this->assertSame('2026', $tokens[0]->params);
        $this->assertSame('p', $tokens[0]->final);
    }

    public function testInvalidCsiFinalEmitsBareEscAndResynchronizes(): void
    {
        // 0x01 is below the 0x40-0x7E final range: the ESC [ pair is emitted
        // as an ESC token and the control byte that follows is still seen.
        $tokens = AnsiTokenizer::new()->parse("\x1b[\x01");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::ESC, $tokens[0]->type);
        $this->assertSame('[', $tokens[0]->data);
        $this->assertSame(Token::CONTROL, $tokens[1]->type);
        $this->assertSame("\x01", $tokens[1]->data);
    }

    public function testOscWithBel(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b]0;hello\x07");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('0;hello', $tokens[0]->data);
        $this->assertSame("\x07", $tokens[0]->terminator);
    }

    public function testOscWithSt(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b]52;c;dGVzdA==\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('52;c;dGVzdA==', $tokens[0]->data);
        $this->assertSame("\x1b\\", $tokens[0]->terminator);
    }

    public function testNonStringTokensCarryNoTerminator(): void
    {
        foreach (AnsiTokenizer::new()->parse("ab\x1b[1m\x1b7\r") as $token) {
            $this->assertSame('', $token->terminator, $token->type);
        }
    }

    public function testDcs(): void
    {
        // XTVERSION reply: ESC P > | xterm(367) ESC \
        $tokens = AnsiTokenizer::new()->parse("\x1bP>|xterm(367)\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::DCS, $tokens[0]->type);
        $this->assertSame('>|xterm(367)', $tokens[0]->data);
    }

    public function testApc(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b_candyzone:S:foo\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::APC, $tokens[0]->type);
        $this->assertSame('candyzone:S:foo', $tokens[0]->data);
    }

    public function testSosAndPmEnvelopes(): void
    {
        $sos = AnsiTokenizer::new()->parse("\x1bXabc\x1b\\");
        $this->assertSame(Token::SOS, $sos[0]->type);
        $this->assertSame('abc', $sos[0]->data);

        $pm = AnsiTokenizer::new()->parse("\x1b^msg\x07");
        $this->assertSame(Token::PM, $pm[0]->type);
        $this->assertSame('msg', $pm[0]->data);
        $this->assertSame("\x07", $pm[0]->terminator);
    }

    public function testShortEscSequence(): void
    {
        $tokens = AnsiTokenizer::new()->parse("\x1b7");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::ESC, $tokens[0]->type);
        $this->assertSame('7', $tokens[0]->data);
    }

    public function testMixedStream(): void
    {
        $tokens = AnsiTokenizer::new()->parse("hello\x1b[31mworld\x1b[0m\n");
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
        $p = AnsiTokenizer::new();
        $tokens = $p->parse("a\x1b[3");
        $this->assertCount(1, $tokens);
        $this->assertSame('a', $tokens[0]->data);

        $tokens = $p->parse('1m');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('31', $tokens[0]->params);
        $this->assertSame('m', $tokens[0]->final);
    }

    public function testLoneTrailingEscIsHeldForNextChunk(): void
    {
        $p = AnsiTokenizer::new();
        $this->assertSame([], $p->parse("\x1b"));
        $tokens = $p->parse('7');
        $this->assertSame(Token::ESC, $tokens[0]->type);
        $this->assertSame('7', $tokens[0]->data);
    }

    public function testIncompleteOscBuffers(): void
    {
        $p = AnsiTokenizer::new();
        $this->assertSame([], $p->parse("\x1b]0;tit"));
        $tokens = $p->parse("le\x07after");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('0;title', $tokens[0]->data);
        $this->assertSame('after', $tokens[1]->data);
    }

    public function testUnterminatedSequenceBeyondTheCapIsRecoveredAsText(): void
    {
        $p = AnsiTokenizer::new();
        // An OSC opener that never meets BEL or ST: buffered while the partial
        // fits MAX_PENDING_BYTES, recovered as text the moment the backlog would
        // outgrow it (no unbounded state, no quadratic rescan across feeds).
        $prefix = "\x1b]0;" . str_repeat('x', AnsiTokenizer::MAX_PENDING_BYTES - 5);
        $this->assertSame([], $p->parse($prefix));   // within the cap: still buffered
        $tokens = $p->parse('yy');                   // pushes the backlog one past it
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame($prefix . 'yy', $tokens[0]->data);
        $this->assertSame([], $p->flush());          // nothing left buffered

        // Resynchronized: the next chunk parses normally.
        $tokens = $p->parse("\x1b[31m");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('m', $tokens[0]->final);
    }

    public function testShortPartialAfterALargeChunkIsStillHeld(): void
    {
        // The cap judges the held partial, not the whole buffer: a chunk
        // larger than MAX_PENDING_BYTES whose tail is a short unfinished CSI
        // must keep that tail for the next call, not emit it as text.
        $p = AnsiTokenizer::new();
        $backlog = str_repeat('a', AnsiTokenizer::MAX_PENDING_BYTES + 4464);
        $tokens = $p->parse($backlog . "\x1b[38;5;");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame($backlog, $tokens[0]->data);

        $tokens = $p->parse('196m');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('38;5;196', $tokens[0]->params);
        $this->assertSame('m', $tokens[0]->final);
        $this->assertSame([], $p->flush());
    }

    public function testFlushReturnsBufferedAsText(): void
    {
        $p = AnsiTokenizer::new();
        $this->assertSame([], $p->parse("\x1b[31"));
        $flushed = $p->flush();
        $this->assertCount(1, $flushed);
        $this->assertSame(Token::TEXT, $flushed[0]->type);
        $this->assertSame("\x1b[31", $flushed[0]->data);
    }

    public function testFlushOnEmptyBufferIsEmpty(): void
    {
        $this->assertSame([], AnsiTokenizer::new()->flush());
    }

    public function testFlushClearsTheBuffer(): void
    {
        $p = AnsiTokenizer::new();
        $p->parse("\x1b[31");
        $p->flush();
        $this->assertSame([], $p->flush());
    }

    public function testMaxPendingBytesIsTheDocumentedCap(): void
    {
        $this->assertSame(65536, AnsiTokenizer::MAX_PENDING_BYTES);
    }

    public function testTokenIsImmutable(): void
    {
        $t = new Token(Token::TEXT, 'x');
        $this->expectException(\Error::class);
        $t->data = 'y';
    }
}

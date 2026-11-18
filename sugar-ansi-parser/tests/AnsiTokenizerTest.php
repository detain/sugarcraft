<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\AnsiTokenizer;
use SugarCraft\AnsiParser\Token;

/**
 * Behavior of the flat stream tokenizer. Cases port sugar-core's Util\Parser
 * suite (tests/Util/ParserTest.php in sugar-core). Util\Parser is not removed
 * until P1-14b, so until then both suites cover the same behavior. The cases
 * also add the edges that suite left unpinned (DEL, 8-bit C1 bytes, invalid CSI
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
        // CandyZone marker: ESC _ candyzone:S:foo ESC \ @rename-keep legacy wire token / historical log
        $tokens = AnsiTokenizer::new()->parse("\x1b_candyzone:S:foo\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::APC, $tokens[0]->type);
        $this->assertSame('candyzone:S:foo', $tokens[0]->data); // @rename-keep legacy wire token / historical log
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
        // outgrow it. This bounds the buffer, and the scan work per feed (see the
        // MAX_PENDING_BYTES docblock for what it does not bound).
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

    public function testPendingBytesReportsTheHeldPartial(): void
    {
        $p = AnsiTokenizer::new();
        $this->assertSame(0, $p->pendingBytes());
        $p->parse("ab\x1b[38;5");
        $this->assertSame(6, $p->pendingBytes());
        $p->parse('m');
        $this->assertSame(0, $p->pendingBytes());
        $p->parse("\x1b[3");
        $p->flush();
        $this->assertSame(0, $p->pendingBytes());
    }

    public function testOscFedOneByteAtATimeMatchesSingleFeed(): void
    {
        // Both BEL and ST terminators, so every resume point sees a different
        // kind of byte. An ESC inside the body is not tested here: it aborts
        // the string (testEscInsideOscAbortsTheStringAndKeepsTheNextKey).
        $stream = "\x1b]0;tit\x07after\x1b]2;a\x1b\\end";
        $single = AnsiTokenizer::new()->parse($stream);
        $this->assertSame(
            [Token::OSC, Token::TEXT, Token::OSC, Token::TEXT],
            array_map(static fn (Token $t): string => $t->type, $single),
        );
        $this->assertEquals(self::coalesceText($single), self::coalesceText(self::feedOneByteAtATime($stream)));
    }

    public function testDcsFedOneByteAtATimeMatchesSingleFeed(): void
    {
        $stream = "\x1bPqabc\x1b\\x\x1b_hi\x07z";
        $single = AnsiTokenizer::new()->parse($stream);
        $this->assertSame(
            [Token::DCS, Token::TEXT, Token::APC, Token::TEXT],
            array_map(static fn (Token $t): string => $t->type, $single),
        );
        $this->assertEquals(self::coalesceText($single), self::coalesceText(self::feedOneByteAtATime($stream)));
    }

    public function testHeldStringResumedAcrossFeedsStillHitsTheCap(): void
    {
        $p = AnsiTokenizer::new();
        $cap = AnsiTokenizer::MAX_PENDING_BYTES;
        // Held partial of exactly cap-1 bytes, then two single-byte feeds that
        // resume the body scan. The cap is judged on the whole held partial.
        $prefix = "\x1b]0;" . str_repeat('x', $cap - 5);
        $this->assertSame([], $p->parse($prefix));
        $this->assertSame([], $p->parse('y'));      // exactly MAX_PENDING_BYTES held
        $tokens = $p->parse('y');                   // one past the cap
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame($prefix . 'yy', $tokens[0]->data);
        $this->assertSame([], $p->flush());

        $tokens = $p->parse("\x1b[31m");
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
    }

    public function testResumeStateIsClearedByTerminationAndByFlush(): void
    {
        // A finished envelope must not leave a resume offset behind for the
        // next one to inherit.
        $p = AnsiTokenizer::new();
        $this->assertSame([], $p->parse("\x1b]0;a"));
        $this->assertSame([Token::OSC], array_map(
            static fn (Token $t): string => $t->type,
            $p->parse("b\x07"),
        ));
        $this->assertSame([], $p->parse("\x1b]1;c"));
        $tokens = $p->parse("\x07");
        $this->assertCount(1, $tokens);
        $this->assertSame('1;c', $tokens[0]->data);

        // A flushed partial must not leave one either.
        $this->assertSame([], $p->parse("\x1b]2;d"));
        $this->assertSame("\x1b]2;d", $p->flush()[0]->data);
        $this->assertSame([], $p->parse("\x1b]3;e"));
        $tokens = $p->parse("\x07");
        $this->assertSame('3;e', $tokens[0]->data);
    }

    public function testEscInsideCsiAbortsTheSequenceAndRestarts(): void
    {
        // The VT500 machine clears on ESC from any CSI state, so the partial
        // "ESC [ 31" is dropped with no token and the SGR after it survives.
        $tokens = AnsiTokenizer::new()->parse("\x1b[31\x1b[32mX");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('32', $tokens[0]->params);
        $this->assertSame('m', $tokens[0]->final);
        $this->assertSame(Token::TEXT, $tokens[1]->type);
        $this->assertSame('X', $tokens[1]->data);
    }

    public function testEscEscRestartsTheSequence(): void
    {
        // The first ESC of "ESC ESC [" is abandoned silently; the bare ESC
        // token of the old behavior stranded "[31mX" as text.
        $tokens = AnsiTokenizer::new()->parse("\x1b\x1b[31mX");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::CSI, $tokens[0]->type);
        $this->assertSame('31', $tokens[0]->params);
        $this->assertSame('m', $tokens[0]->final);
        $this->assertSame('X', $tokens[1]->data);

        $tokenizer = AnsiTokenizer::new();
        $this->assertSame([], $tokenizer->parse("\x1b\x1b"));
        $rest = $tokenizer->flush();
        $this->assertCount(1, $rest);
        $this->assertSame("\x1b", $rest[0]->data);
    }

    public function testEscInsideOscAbortsTheStringAndKeepsTheNextKey(): void
    {
        // The body stops at the ESC: the CSI A after it is a key press, not
        // part of the OSC body. The OSC carries no terminator because ESC
        // closed it, which a byte-offset consumer relies on.
        $tokens = AnsiTokenizer::new()->parse("\x1b]0;t\x1b[A");
        $this->assertCount(2, $tokens);
        $this->assertSame(Token::OSC, $tokens[0]->type);
        $this->assertSame('0;t', $tokens[0]->data);
        $this->assertSame('', $tokens[0]->terminator);
        $this->assertSame(Token::CSI, $tokens[1]->type);
        $this->assertSame('A', $tokens[1]->final);
    }

    public function testEscInsideDcsAndApcAbortTheString(): void
    {
        $dcs = AnsiTokenizer::new()->parse("\x1bPq1\x1b[2J");
        $this->assertCount(2, $dcs);
        $this->assertSame(Token::DCS, $dcs[0]->type);
        $this->assertSame('q1', $dcs[0]->data);
        $this->assertSame('J', $dcs[1]->final);

        $apc = AnsiTokenizer::new()->parse("\x1b_hi\x1b7");
        $this->assertCount(2, $apc);
        $this->assertSame(Token::APC, $apc[0]->type);
        $this->assertSame('hi', $apc[0]->data);
        $this->assertSame(Token::ESC, $apc[1]->type);
        $this->assertSame('7', $apc[1]->data);
    }

    public function testStEscapeStillClosesAStringAfterAnAbortCandidate(): void
    {
        // ESC \ is ST, not an abort: a body with no inner ESC keeps its
        // terminator, so the ESC-abort branch does not steal real closers.
        $tokens = AnsiTokenizer::new()->parse("\x1b]0;t\x1b\\");
        $this->assertCount(1, $tokens);
        $this->assertSame('0;t', $tokens[0]->data);
        $this->assertSame("\x1b\\", $tokens[0]->terminator);
    }

    public function testEscInsideStringOrCsiSplitAcrossFeedsMatchesOneShot(): void
    {
        // A trailing ESC inside a body is held until the next byte decides
        // ST or abort; every split and the byte-wise feed must agree with
        // the one-shot tokens.
        foreach (["\x1b]0;t\x1b[A", "\x1b[31\x1b[32mX", "\x1bPq1\x1b\x1b[2J"] as $seq) {
            $oneShot = AnsiTokenizer::new()->parse($seq);
            $n = strlen($seq);
            for ($cut = 1; $cut < $n; $cut++) {
                $tokenizer = AnsiTokenizer::new();
                $fed = array_merge(
                    $tokenizer->parse(substr($seq, 0, $cut)),
                    $tokenizer->parse(substr($seq, $cut)),
                );
                $this->assertEquals($oneShot, $fed, bin2hex($seq) . " cut at $cut");
            }

            $tokenizer = AnsiTokenizer::new();
            $bytewise = [];
            for ($k = 0; $k < $n; $k++) {
                foreach ($tokenizer->parse($seq[$k]) as $token) {
                    $bytewise[] = $token;
                }
            }
            $this->assertEquals($oneShot, $bytewise, bin2hex($seq) . ' byte-wise');
        }
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

    /**
     * Joins adjacent TEXT tokens. A text run is cut at every feed boundary, so
     * one-byte feeds split runs that a single feed keeps whole; the stream's
     * tokens are the same once those cuts are removed.
     *
     * @param list<Token> $tokens
     * @return list<Token>
     */
    private static function coalesceText(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $n = count($out);
            if ($token->type === Token::TEXT && $n > 0 && $out[$n - 1]->type === Token::TEXT) {
                $out[$n - 1] = new Token(Token::TEXT, $out[$n - 1]->data . $token->data);
            } else {
                $out[] = $token;
            }
        }
        return $out;
    }

    /** @return list<Token> */
    private static function feedOneByteAtATime(string $stream): array
    {
        $p = AnsiTokenizer::new();
        $tokens = [];
        for ($i = 0, $n = strlen($stream); $i < $n; $i++) {
            foreach ($p->parse($stream[$i]) as $token) {
                $tokens[] = $token;
            }
        }
        foreach ($p->flush() as $token) {
            $tokens[] = $token;
        }
        return $tokens;
    }
}

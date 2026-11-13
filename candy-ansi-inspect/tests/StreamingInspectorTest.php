<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\Segment;
use SugarCraft\AnsiInspect\SequenceSegment;
use SugarCraft\AnsiInspect\StreamingInspector;
use SugarCraft\AnsiInspect\TextSegment;
use PHPUnit\Framework\TestCase;

final class StreamingInspectorTest extends TestCase
{
    public function testFeedPlainTextIsBufferedUntilFinish(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed('hello');
        // Text is buffered until a sequence or finish() is called
        $this->assertCount(0, $segs);
        $segs = $inspector->finish();
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello', $segs[0]->raw());
    }

    public function testFeedSequenceYieldsImmediately(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1b[31m");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame("\x1b[31m", $segs[0]->raw());
        $this->assertStringContainsString('foreground red', $segs[0]->describe());
    }

    public function testMultipleFeedsAccumulateSegments(): void
    {
        $inspector = new StreamingInspector();
        // Feed in parts: sequence + text + sequence
        $segs = $inspector->feed("\x1b[1m");
        $this->assertCount(1, $segs); // sequence returned immediately
        $segs = $inspector->feed("bold");
        $this->assertCount(0, $segs); // text buffered
        $segs = $inspector->feed("\x1b[0m");
        // text ("bold") flushes when ESC seen, then new sequence
        $this->assertCount(2, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('bold', $segs[0]->raw());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x1b[0m", $segs[1]->raw());
    }

    public function testEscAtChunkBoundaryIsBuffered(): void
    {
        $inspector = new StreamingInspector();
        // Feed "hello" + ESC, but no more bytes — ESC alone is incomplete.
        // In the Parser-based streaming, ESC (Action::Clear) does NOT call
        // execute(), so text is NOT flushed. Text is buffered until a sequence
        // is completed in the next feed.
        $segs = $inspector->feed("hello\x1b");
        $this->assertCount(0, $segs); // Text still buffered, ESC not a sequence

        // Now feed the rest of the CSI sequence. When 'm' completes the CSI,
        // text is flushed via csiDispatch() calling flushText().
        $segs = $inspector->feed("[31m");
        $this->assertCount(2, $segs); // text("hello") + CSI sequence
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello', $segs[0]->raw());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x1b[31m", $segs[1]->raw());
    }

    public function testPartialCsiBuffered(): void
    {
        $inspector = new StreamingInspector();
        // Feed ESC [ 1 only — missing final byte
        $segs = $inspector->feed("\x1b[1");
        $this->assertCount(0, $segs); // Nothing complete yet

        // Feed the 'm' to complete the sequence
        $segs = $inspector->feed("m");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame("\x1b[1m", $segs[0]->raw());
    }

    public function testFinishFlushesRemainingText(): void
    {
        $inspector = new StreamingInspector();
        $inspector->feed("hello");
        $segs = $inspector->finish();
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello', $segs[0]->raw());
    }

    public function testFinishAfterSequenceYieldsNothing(): void
    {
        $inspector = new StreamingInspector();
        $inspector->feed("\x1b[0m");
        $segs = $inspector->finish();
        $this->assertCount(0, $segs);
    }

    public function testEmptyFeedReturnsEmptyArray(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed('');
        $this->assertCount(0, $segs);
    }

    public function testInterleavedTextAndSequences(): void
    {
        $inspector = new StreamingInspector();
        // "pre " flushes when ESC seen, " red " flushes at next ESC, " post" at finish
        $segs = $inspector->feed("pre \x1b[31m red \x1b[0m ");
        // Expected: text("pre "), seq(ESC[31m), text(" red "), seq(ESC[0m)
        $this->assertCount(4, $segs);

        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('pre ', $segs[0]->raw());

        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x1b[31m", $segs[1]->raw());

        $this->assertInstanceOf(TextSegment::class, $segs[2]);
        $this->assertSame(' red ', $segs[2]->raw());

        $this->assertInstanceOf(SequenceSegment::class, $segs[3]);
        $this->assertSame("\x1b[0m", $segs[3]->raw());
        // Note: trailing text would be flushed by finish()
    }

    public function testSs3SequenceComplete(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1bOP");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('F1', $segs[0]->describe());
    }

    public function testPartialSs3Buffered(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1bO");
        $this->assertCount(0, $segs);

        $segs = $inspector->feed("P");
        $this->assertCount(1, $segs);
        $this->assertStringContainsString('F1', $segs[0]->describe());
    }

    public function testOscSequence(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1b]0;title\x07");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('set window title', $segs[0]->describe());
    }

    public function testPartialOscBuffered(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1b]0;title");
        $this->assertCount(0, $segs);

        $segs = $inspector->feed("\x07");
        $this->assertCount(1, $segs);
        $this->assertStringContainsString('set window title', $segs[0]->describe());
    }

    public function testTwoByteEscBuffered(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1b7");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('save cursor', $segs[0]->describe());
    }

    public function testDcsSequence(): void
    {
        $inspector = new StreamingInspector();
        // The single trailing \x1b\\ in the input IS the DCS terminator: it is
        // consumed by the DCS segment and must not reappear as a second,
        // bare-ESC segment. Regression (SP-R2): 2 segments — DCS + ghost ESC \.
        // Also, '|' (0x7C) is NOT an intermediate byte (those are 0x20-0x2F),
        // so it becomes part of the data: ">xterm" not ">|xterm".
        $segs = $inspector->feed("\x1bP>|xterm\x1b\\");
        $this->assertCount(1, $segs); // the DCS sequence alone
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('terminal version', $segs[0]->describe());
        // The terminator survives exactly once, inside the DCS segment.
        $this->assertSame(1, substr_count($segs[0]->raw(), "\x1b\\"));
        $this->assertStringEndsWith("\x1b\\", $segs[0]->raw());
    }

    public function testApcSequence(): void
    {
        $inspector = new StreamingInspector();
        // Same terminator contract as the DCS case above: one APC segment whose
        // raw bytes already end in the ST, no ghost `ESC \` behind it.
        $input = "\x1b_candyzone:S:btn\x1b\\";
        $segs = $inspector->feed($input);
        $this->assertCount(1, $segs); // APC sequence only
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame($input, $segs[0]->raw());
        $this->assertStringContainsString('CandyZone marker', $segs[0]->describe()); // @rename-keep legacy wire token / historical log
    }

    // --- Step 9: streaming matches one-shot for C0, split CSI, APC, underline colon ---

    /**
     * C0 BEL in streaming must be isolated as its own segment, matching
     * one-shot Inspector::parse() output.  Before the Step 9 fix the old
     * hand-rolled parser buffered C0 inside text and never isolated it.
     */
    public function testStreamedC0IsIsolated(): void
    {
        $streaming = new StreamingInspector();
        // Split the input across two feed() calls.
        $segs1 = $streaming->feed("ab\x07");
        $segs2 = $streaming->feed("cd");
        $segs3 = $streaming->finish();

        // Concat all streaming segments for comparison with one-shot.
        $allStreaming = array_merge($segs1, $segs2, $segs3);
        $oneShot = Inspector::parse("ab\x07cd");

        $oneShotCount = count($oneShot);
        $this->assertSame($oneShotCount, count($allStreaming));
        for ($i = 0; $i < $oneShotCount; $i++) {
            $this->assertSame($oneShot[$i]->raw(), $allStreaming[$i]->raw());
        }
    }

    /**
     * APC kitty graphics protocol streamed through multiple chunks must
     * produce the same segments as one-shot parsing.
     */
    public function testStreamedApcKittyGraphics(): void
    {
        $streaming = new StreamingInspector();
        $segs1 = $streaming->feed("\x1b_G");
        $segs2 = $streaming->feed("i=1,s=1,v=1\x1b\\");
        $allStreaming = array_merge($segs1, $segs2, $streaming->finish());
        $oneShot = Inspector::parse("\x1b_Gi=1,s=1,v=1\x1b\\");

        $oneShotCount = count($oneShot);
        $this->assertSame($oneShotCount, count($allStreaming));
        for ($i = 0; $i < $oneShotCount; $i++) {
            $this->assertSame($oneShot[$i]->raw(), $allStreaming[$i]->raw());
        }
    }

    /**
     * SGR underline colon form (4:2) streamed must match one-shot.
     */
    public function testStreamedSgrUnderlineColonForm(): void
    {
        $streaming = new StreamingInspector();
        $segs = $streaming->feed("\x1b[1;4:2m");
        $oneShot = Inspector::parse("\x1b[1;4:2m");

        $this->assertCount(count($oneShot), $segs);
        for ($i = 0; $i < count($oneShot); $i++) {
            $this->assertSame($oneShot[$i]->raw(), $segs[$i]->raw());
            $this->assertSame($oneShot[$i]->describe(), $segs[$i]->describe());
        }
    }

    /**
     * A split CSI sequence (params split across two feed() calls) must be
     * reassembled correctly.
     */
    public function testSplitCsiBuffered(): void
    {
        $streaming = new StreamingInspector();
        $segs1 = $streaming->feed("\x1b[1");
        $this->assertCount(0, $segs1); // Nothing complete yet.
        $segs2 = $streaming->feed("m");
        $this->assertCount(1, $segs2);
        $this->assertInstanceOf(SequenceSegment::class, $segs2[0]);
        $this->assertSame("\x1b[1m", $segs2[0]->raw());
    }

    // --- Step 10: C1 bytes in streaming text ---

    /**
     * A C1 byte mid-text is isolated as its own segment — 0x86 is SSA (start
     * of selected area) in this library's own C0C1 roster (NEL is 0x85; the
     * former docblock here mislabelled it, and C0C1Test pins the names) —
     * and the streamed result is BYTE-IDENTICAL to the one-shot parse,
     * segment [1] included.
     *
     * The superseded docblock claimed a streaming-vs-one-shot divergence in
     * the C1 segment while asserting only segments [0] and [2]: the segment
     * it called unstable was never checked, so a regression blanking it
     * stayed green (finding 024). `AnsiHandler::feed()` drives the parser one
     * byte at a time by design, so there is no divergence to claim — the
     * contract is full fingerprint equality, pinned below.
     */
    public function testStreamedC1BytesAreIsolatedExactlyLikeOneShot(): void
    {
        $streaming = new StreamingInspector();
        $allStreaming = array_merge($streaming->feed("a\x86b"), $streaming->finish());

        $oneShot = Inspector::parse("a\x86b");
        $this->assertSame(self::fingerprint($oneShot), self::fingerprint($allStreaming));
        $this->assertInstanceOf(SequenceSegment::class, $allStreaming[1]);
        $this->assertSame("\x86", $allStreaming[1]->raw());
        $this->assertStringContainsString('C1 SSA', $allStreaming[1]->describe());
    }

    /**
     * `finish()` resets the handler between sessions (keeping the parser
     * binding), and a session that ended UNTERMINATED must not glue its
     * dangling tail onto whatever the next session feeds (finding 026: a
     * designed contract with zero tests). Boundary leg: a lone trailing ESC
     * ends its session inside the finish() report, and the same inspector
     * still opens the next session from Ground.
     */
    public function testSessionsAfterAnUnterminatedFinishOpenClean(): void
    {
        $streaming = new StreamingInspector();
        $session1 = array_merge($streaming->feed("first\x1b[31"), $streaming->finish());
        $this->assertStringContainsString(
            'truncated CSI',
            $session1[count($session1) - 1]->describe(),
            'session one keeps its own dangling tail',
        );

        $session2 = array_merge($streaming->feed("second\x1b[32m"), $streaming->finish());
        $this->assertSame(
            self::fingerprint(Inspector::parse("second\x1b[32m")),
            self::fingerprint($session2),
            'a fresh session must stream exactly like a one-shot parse of the same bytes',
        );

        // Boundary leg: lone trailing ESC.
        $edge = new StreamingInspector();
        $edge1 = array_merge($edge->feed("x\x1b"), $edge->finish());
        $this->assertSame(
            self::fingerprint(Inspector::parse("x\x1b")),
            self::fingerprint($edge1),
        );
        $edge2 = array_merge($edge->feed("y"), $edge->finish());
        $this->assertSame(['y'], array_map(static fn($s): string => $s->raw(), $edge2));
    }

    /**
     * @param iterable<Segment> $segments
     *
     * @return list<string>
     */
    private static function fingerprint(iterable $segments): array
    {
        $list = is_array($segments) ? $segments : iterator_to_array($segments);

        return array_map(
            static fn(Segment $s): string => $s::class . '|' . $s->raw() . '|' . $s->describe(),
            $list,
        );
    }

    // --- End-of-stream tail: trailing bare ESC + dangling SS3 ---

    /**
     * A lone ESC (0x1b) at end-of-stream must be emitted by finish() as its
     * own SequenceSegment. Regression: finish() used to check the parser state
     * AFTER flush() reset it to Ground, so the branch was dead and the trailing
     * bare ESC was silently dropped.
     */
    public function testFinishEmitsTrailingBareEsc(): void
    {
        $inspector = new StreamingInspector();
        // Lone ESC with no following byte — incomplete, held in Escape state.
        $segs = $inspector->feed("\x1b");
        $this->assertCount(0, $segs);

        $segs = $inspector->finish();
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame("\x1b", $segs[0]->raw());
    }

    /**
     * A dangling SS3 (ESC O with no final byte) at end-of-stream must be
     * finalised by finish() WITHOUT throwing. Regression: finish() used to
     * write the private AnsiHandler::$segments and call the protected
     * getSs3Intermediate() from a foreign class, raising a fatal \Error.
     */
    public function testFinishEmitsDanglingSs3WithoutError(): void
    {
        $inspector = new StreamingInspector();
        // ESC O buffers an SS3 intermediate awaiting a final byte.
        $segs = $inspector->feed("\x1bO");
        $this->assertCount(0, $segs);

        $segs = $inspector->finish();
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame("\x1bO", $segs[0]->raw());
        $this->assertStringContainsString('SS3', $segs[0]->describe());
    }
}

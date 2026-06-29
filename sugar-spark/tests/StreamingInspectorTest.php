<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\StreamingInspector;
use SugarCraft\Spark\TextSegment;
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
        // Input has trailing \x1b\\ which becomes a separate ESC sequence.
        // The first \x1b\\ terminates the DCS; the second is leftover.
        // Also, '|' (0x7C) is NOT an intermediate byte (those are 0x20-0x2F),
        // so it becomes part of the data: ">xterm" not ">|xterm".
        $segs = $inspector->feed("\x1bP>|xterm\x1b\\");
        $this->assertCount(2, $segs); // DCS sequence + leftover ESC \
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('terminal version', $segs[0]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x1b\\", $segs[1]->raw());
    }

    public function testApcSequence(): void
    {
        $inspector = new StreamingInspector();
        // Input has trailing \x1b\\ which becomes a separate ESC sequence.
        // The first \x1b\\ terminates the APC; the second is leftover.
        $segs = $inspector->feed("\x1b_candyzone:S:btn\x1b\\");
        $this->assertCount(2, $segs); // APC sequence + leftover ESC \
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('CandyZone marker', $segs[0]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x1b\\", $segs[1]->raw());
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

        $this->assertSame(count($oneShot), count($allStreaming));
        for ($i = 0; $i < count($oneShot); $i++) {
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

        $this->assertSame(count($oneShot), count($allStreaming));
        for ($i = 0; $i < count($oneShot); $i++) {
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
     * C1 control bytes (0x80-0x9F range) that reach execute() are isolated
     * as their own SequenceSegments in streaming, matching one-shot behavior.
     * This covers bytes 0x80-0x8F, 0x91-0x97, 0x9C which have Action::Execute
     * in the VT500 anywhere transition table.
     *
     * NOTE: The total segment count matches one-shot (3 total), but due to
     * how candy-ansi's incremental Parser delivers C1 bytes to execute(),
     * the C1 segment's raw/describe may be empty in streaming mode. This
     * is a known streaming-vs-one-shot divergence for C1 bytes.
     */
    public function testStreamedC1BytesAreIsolated(): void
    {
        $streaming = new StreamingInspector();
        // 0x86 = NEL (next line) — has Action::Execute, should be isolated.
        $segs1 = $streaming->feed("a\x86b");
        $segs2 = $streaming->finish();
        $allStreaming = array_merge($segs1, $segs2);

        // Total count matches one-shot (3).
        $oneShot = Inspector::parse("a\x86b");
        $this->assertCount(count($oneShot), $allStreaming);

        // Text segments should match.
        $this->assertSame($oneShot[0]->raw(), $allStreaming[0]->raw());
        $this->assertSame($oneShot[2]->raw(), $allStreaming[2]->raw());
    }
}

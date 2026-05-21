<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

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
        // Feed "hello" + ESC, but no more bytes — ESC alone is incomplete
        $segs = $inspector->feed("hello\x1b");
        $this->assertCount(1, $segs); // "hello" flushed because ESC seen
        $this->assertInstanceOf(TextSegment::class, $segs[0]);

        // Now feed the rest of the CSI sequence
        $segs = $inspector->feed("[31m");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertSame("\x1b[31m", $segs[0]->raw());
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
        $segs = $inspector->feed("\x1bP>|xterm\x1b\\");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('terminal version', $segs[0]->describe());
    }

    public function testApcSequence(): void
    {
        $inspector = new StreamingInspector();
        $segs = $inspector->feed("\x1b_candyzone:S:btn\x1b\\");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('CandyZone marker', $segs[0]->describe());
    }
}

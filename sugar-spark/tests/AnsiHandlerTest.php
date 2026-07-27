<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use SugarCraft\Spark\AnsiHandler;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\TextSegment;
use PHPUnit\Framework\TestCase;

final class AnsiHandlerTest extends TestCase
{
    public function testParsePlainTextReturnsOneTextSegment(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse('hello world');

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(TextSegment::class, $segments[0]);
        $this->assertSame('hello world', $segments[0]->raw());
    }

    public function testParseEmptyStringReturnsEmptyArray(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse('');

        $this->assertCount(0, $segments);
    }

    public function testParseSgrSequenceReturnsSequenceSegment(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[31m");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertSame("\x1b[31m", $segments[0]->raw());
        $this->assertStringContainsString('foreground red', $segments[0]->describe());
    }

    public function testParseBoldSequence(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[1m");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('bold', $segments[0]->describe());
    }

    public function testParseMixedTextAndSequences(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[31mhello\x1b[0m world");

        $this->assertCount(4, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertInstanceOf(TextSegment::class, $segments[1]);
        $this->assertSame('hello', $segments[1]->raw());
        $this->assertInstanceOf(SequenceSegment::class, $segments[2]);
        $this->assertInstanceOf(TextSegment::class, $segments[3]);
        $this->assertSame(' world', $segments[3]->raw());
    }

    public function testParseC0ControlCharacterIsolated(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("a\x07b");

        $this->assertCount(3, $segments);
        $this->assertInstanceOf(TextSegment::class, $segments[0]);
        $this->assertSame('a', $segments[0]->raw());
        $this->assertInstanceOf(SequenceSegment::class, $segments[1]);
        $this->assertSame("\x07", $segments[1]->raw());
        $this->assertStringContainsString('BEL', $segments[1]->describe());
        $this->assertInstanceOf(TextSegment::class, $segments[2]);
        $this->assertSame('b', $segments[2]->raw());
    }

    public function testParseTabCharacter(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\thello");

        $this->assertCount(2, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('HT', $segments[0]->describe());
        $this->assertInstanceOf(TextSegment::class, $segments[1]);
        $this->assertSame('hello', $segments[1]->raw());
    }

    public function testParseOscWindowTitle(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b]0;hello\x07");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('set window title', $segments[0]->describe());
    }

    public function testParseTwoByteEscSequence(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b7");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('save cursor', $segments[0]->describe());
    }

    public function testParseSs3Sequence(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1bOP");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('F1', $segments[0]->describe());
    }

    public function testParseDcsSequence(): void
    {
        $handler = new AnsiHandler();
        // DCS with xterm XTVERSION payload
        $segments = $handler->parse("\x1bP>|xterm(367)\x1b\\");

        $this->assertGreaterThanOrEqual(1, count($segments));
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('terminal version', $segments[0]->describe());
    }

    public function testParseApcSequence(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b_candyzone:S:btn\x1b\\");

        $this->assertCount(2, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('CandyZone marker', $segments[0]->describe());
    }

    public function testParseSosPmSequence(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1bXtest\x1b\\");

        $this->assertCount(2, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('SOS', $segments[0]->describe());
    }

    public function testParseTrailingBareEsc(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("hi\x1b");

        $this->assertCount(2, $segments);
        $this->assertInstanceOf(TextSegment::class, $segments[0]);
        $this->assertSame('hi', $segments[0]->raw());
        $this->assertInstanceOf(SequenceSegment::class, $segments[1]);
        $this->assertSame("\x1b", $segments[1]->raw());
    }

    public function testParseSgrUnderlineColonForm(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[4:2m");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('underline double', $segments[0]->describe());
    }

    public function testParseBrightForeground(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[91m");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('foreground bright red', $segments[0]->describe());
    }

    public function testParseTrueColor(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[38;2;255;128;0m");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('rgb(255,128,0)', $segments[0]->describe());
    }

    public function testParse256Color(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[48;5;202m");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('background 256-color 202', $segments[0]->describe());
    }

    public function testResetClearsAllState(): void
    {
        $handler = new AnsiHandler();
        $handler->parse("hello");
        $this->assertCount(1, $handler->drainSegments());

        $handler->reset();
        $this->assertCount(0, $handler->drainSegments());
    }

    public function testDrainSegmentsReturnsAndClears(): void
    {
        $handler = new AnsiHandler();
        $handler->parse("hello");
        $handler->parse(" world");

        // First drain returns all accumulated segments from both parse() calls.
        $segs = $handler->drainSegments();
        $this->assertGreaterThanOrEqual(1, count($segs));

        // Second drain returns empty array.
        $this->assertCount(0, $handler->drainSegments());
    }

    public function testParseIsIdempotentPerCall(): void
    {
        $handler = new AnsiHandler();
        $segs1 = $handler->parse("\x1b[31mred\x1b[0m");
        $segs2 = $handler->parse("\x1b[32mgreen\x1b[0m");

        // Each parse() call starts fresh.
        $this->assertCount(3, $segs1);
        $this->assertCount(3, $segs2);

        // drainSegments() after second parse shows only the green segments.
        $drained = $handler->drainSegments();
        $this->assertCount(3, $drained);
    }

    public function testSequenceSegmentDescribePrintifiesEsc(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[0m");

        $desc = $segments[0]->describe();
        $this->assertStringContainsString('ESC[0m', $desc);
        $this->assertStringNotContainsString("\x1b", $desc);
    }

    public function testParseUnknownCsiFallback(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[1;2Z");

        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringContainsString('CSI', $segments[0]->describe());
    }

    public function testParseDecPrivateMode(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[?2004h");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('bracketed paste', $segments[0]->describe());
    }

    public function testParseCursorMove(): void
    {
        $handler = new AnsiHandler();
        $up = $handler->parse("\x1b[3A")[0];
        $this->assertStringContainsString('cursor up 3', $up->describe());

        $down = $handler->parse("\x1b[B")[0];
        $this->assertStringContainsString('cursor down 1', $down->describe());
    }

    public function testParseEraseLine(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[2K");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('erase line 2', $segments[0]->describe());
    }

    public function testParseResetStateClearsInProgressFlags(): void
    {
        $handler = new AnsiHandler();
        // Feed OSC without terminator, then reset.
        $handler->parse("\x1b]0;title");
        $handler->reset();
        // After reset, the handler should be in a clean state.
        $segments = $handler->parse("\x1b[0m");
        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
    }
}

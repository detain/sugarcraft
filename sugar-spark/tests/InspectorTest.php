<?php

declare(strict_types=1);

namespace CandyCore\Spark\Tests;

use CandyCore\Spark\Inspector;
use CandyCore\Spark\SequenceSegment;
use CandyCore\Spark\TextSegment;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testPlainTextProducesSingleSegment(): void
    {
        $segs = Inspector::parse('hello world');
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello world', $segs[0]->describe());
    }

    public function testSgrResetIsDescribed(): void
    {
        $segs = Inspector::parse("\x1b[0m");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('SGR reset', $segs[0]->describe());
        $this->assertStringContainsString('ESC[0m',    $segs[0]->describe());
    }

    public function testForegroundRed(): void
    {
        $seg = Inspector::parse("\x1b[31m")[0];
        $this->assertStringContainsString('foreground red', $seg->describe());
    }

    public function testBoldUnderlineMagenta(): void
    {
        $seg = Inspector::parse("\x1b[1;4;35m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold',       $desc);
        $this->assertStringContainsString('underline',  $desc);
        $this->assertStringContainsString('foreground magenta', $desc);
    }

    public function testTrueColor(): void
    {
        $seg = Inspector::parse("\x1b[38;2;255;128;0m")[0];
        $this->assertStringContainsString('rgb(255,128,0)', $seg->describe());
    }

    public function testBackground256(): void
    {
        $seg = Inspector::parse("\x1b[48;5;202m")[0];
        $this->assertStringContainsString('background 256-color 202', $seg->describe());
    }

    public function testBrightForeground(): void
    {
        $seg = Inspector::parse("\x1b[91m")[0];
        $this->assertStringContainsString('foreground bright red', $seg->describe());
    }

    public function testCursorMoves(): void
    {
        $up    = Inspector::parse("\x1b[3A")[0];
        $down  = Inspector::parse("\x1b[B")[0];
        $right = Inspector::parse("\x1b[C")[0];
        $home  = Inspector::parse("\x1b[H")[0];
        $this->assertStringContainsString('cursor up 3',     $up->describe());
        $this->assertStringContainsString('cursor down 1',   $down->describe());
        $this->assertStringContainsString('cursor right 1',  $right->describe());
        $this->assertStringContainsString('cursor position', $home->describe());
    }

    public function testEraseOps(): void
    {
        $this->assertStringContainsString('erase line 2',     Inspector::parse("\x1b[2K")[0]->describe());
        $this->assertStringContainsString('erase display 0',  Inspector::parse("\x1b[J")[0]->describe());
    }

    public function testDecPrivateModes(): void
    {
        $this->assertStringContainsString('enable bracketed paste',    Inspector::parse("\x1b[?2004h")[0]->describe());
        $this->assertStringContainsString('disable cursor visibility', Inspector::parse("\x1b[?25l")[0]->describe());
        $this->assertStringContainsString('enable alternate screen',   Inspector::parse("\x1b[?1049h")[0]->describe());
        $this->assertStringContainsString('enable mouse cell motion',  Inspector::parse("\x1b[?1002h")[0]->describe());
    }

    public function testFunctionKeysViaTilde(): void
    {
        $this->assertStringContainsString('F1',  Inspector::parse("\x1b[11~")[0]->describe());
        $this->assertStringContainsString('F12', Inspector::parse("\x1b[24~")[0]->describe());
    }

    public function testBracketedPasteMarkers(): void
    {
        $this->assertStringContainsString('bracketed paste start', Inspector::parse("\x1b[200~")[0]->describe());
        $this->assertStringContainsString('bracketed paste end',   Inspector::parse("\x1b[201~")[0]->describe());
    }

    public function testSs3FunctionKeys(): void
    {
        $this->assertStringContainsString('F1', Inspector::parse("\x1bOP")[0]->describe());
        $this->assertStringContainsString('F4', Inspector::parse("\x1bOS")[0]->describe());
    }

    public function testOscWindowTitle(): void
    {
        $seg = Inspector::parse("\x1b]0;hello\x07")[0];
        $this->assertStringContainsString('set window title to "hello"', $seg->describe());
    }

    public function testOscHyperlink(): void
    {
        $seg = Inspector::parse("\x1b]8;;https://example.com\x1b\\")[0];
        $this->assertStringContainsString('hyperlink', $seg->describe());
    }

    public function testTwoByteEsc(): void
    {
        $this->assertStringContainsString('save cursor', Inspector::parse("\x1b7")[0]->describe());
        $this->assertStringContainsString('restore cursor', Inspector::parse("\x1b8")[0]->describe());
    }

    public function testMixedTextAndSequences(): void
    {
        $segs = Inspector::parse("\x1b[31mhello\x1b[0m world");
        $this->assertCount(4, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertInstanceOf(TextSegment::class,     $segs[1]);
        $this->assertSame('hello', $segs[1]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[2]);
        $this->assertInstanceOf(TextSegment::class,     $segs[3]);
        $this->assertSame(' world', $segs[3]->describe());
    }

    public function testReportRendersOneLinePerSegment(): void
    {
        $report = Inspector::report("\x1b[1mbold\x1b[0m");
        $lines  = explode("\n", $report);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('bold',  $lines[0]);
        $this->assertSame('bold', $lines[1]);
        $this->assertStringContainsString('reset', $lines[2]);
    }

    public function testUnknownCsiFallsBackToGeneric(): void
    {
        // Random unrecognised CSI — should not crash, should describe.
        $seg = Inspector::parse("\x1b[1;2Z")[0];
        $this->assertStringContainsString('CSI', $seg->describe());
    }

    public function testRawBytesPreserved(): void
    {
        $seg = Inspector::parse("\x1b[31m")[0];
        $this->assertSame("\x1b[31m", $seg->raw());
    }

    public function testBareEscAtEndOfInput(): void
    {
        $seg = Inspector::parse("hi\x1b")[1];
        $this->assertSame("\x1b", $seg->raw());
    }
}

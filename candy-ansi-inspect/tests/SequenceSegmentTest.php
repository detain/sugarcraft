<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\SequenceSegment;

/**
 * The raw column of the report is display-sanitized (finding 002 / AIP-02):
 * captured control bytes are tokenized exactly like label bytes, so hostile
 * payloads can neither forge segment lines nor mutate the viewing terminal,
 * while `raw()` stays verbatim for library consumers.
 */
final class SequenceSegmentTest extends TestCase
{
    public function testControlBytesAreTokenizedInDescribe(): void
    {
        $segment = new SequenceSegment("\x1b[1;2\r\x08m", 'SGR test');

        $this->assertStringNotContainsString("\r", $segment->describe());
        $this->assertStringNotContainsString("\x08", $segment->describe());
        $this->assertStringContainsString('CR', $segment->describe());
        $this->assertStringContainsString('BS', $segment->describe());
        $this->assertStringContainsString('  SGR test', $segment->describe());
    }

    public function testEightBitC1BytesAreTokenizedNotReplayed(): void
    {
        // 0x8D RI and 0x88 HTS inside a payload would scroll the report and
        // set a tab stop if printed raw; 0x9B would re-arm a CSI parser on an
        // 8-bit-capable terminal. (The findings text wrote "HTS" against 0x8b;
        // the sanitizer names 0x88 — an unnamed 0x8B still escapes to `\x8B`,
        // never a raw byte.)
        $describe = (new SequenceSegment("\x1b]0;\x8d\x88\x9b\x8b", 'osc — tokenized for display'))->describe();

        foreach (["\x8d", "\x88", "\x9b", "\x8b"] as $control) {
            $this->assertStringNotContainsString($control, $describe, bin2hex($control));
        }
        $this->assertStringContainsString('RI', $describe);
        $this->assertStringContainsString('HTS', $describe);
        $this->assertStringContainsString('CSI', $describe);
        $this->assertStringContainsString('\x8B', $describe, 'unnamed C1 still renders as an escape token');
    }

    public function testPrintableAsciiAndUtf8RunesSurviveVerbatim(): void
    {
        // A captured German or CJK window title must not be mangled by the
        // raw-column tokenizer — only control bytes become tokens.
        $segment = new SequenceSegment("title: \xe4\xb8\xad\xe6\x96\x87 \xe2\x9c\x93", 'set window title');
        $this->assertSame('title: 中文 ✓  set window title', $segment->describe());

        $bytes = "a\x1b[0m—b";
        $this->assertSame('ESC[0m—b', SequenceSegment::printable("\x1b[0m—b"));
        $this->assertSame($bytes, (new SequenceSegment($bytes, 'l'))->raw(), 'raw() is untouched');
    }

    public function testEscapeStillRendersAsTheEscToken(): void
    {
        $this->assertSame('ESC[31m', SequenceSegment::printable("\x1b[31m"));
        $this->assertSame('plain text', SequenceSegment::printable('plain text'));
        $this->assertSame('ESC]0;hiESC\\', SequenceSegment::printable("\x1b]0;hi\x1b\\"));
    }

    public function testDelByteIsTokenizedInRawColumn(): void
    {
        $this->assertStringNotContainsString("\x7f", (new SequenceSegment("a\x7fb", 'x'))->describe());
        $this->assertStringContainsString('DEL', SequenceSegment::printable("\x7f"));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\TextSegment;

final class C0InTextTest extends TestCase
{
    public function testC0TabIsolatesText(): void
    {
        // Tab (0x09) in text causes a flush - per step 10.16 spec.
        $segs = Inspector::parse("hello\tworld");
        $this->assertCount(3, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello', $segs[0]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertStringContainsString('HT (horizontal tab)', $segs[1]->describe());
        $this->assertInstanceOf(TextSegment::class, $segs[2]);
        $this->assertSame('world', $segs[2]->describe());
    }

    public function testC0NullCharacterIsolated(): void
    {
        $segs = Inspector::parse("\x00");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('NUL (null)', $segs[0]->describe());
        $this->assertSame("\x00", $segs[0]->raw());
    }

    public function testC0BellCharacterIsolated(): void
    {
        $segs = Inspector::parse("\x07");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('BEL (bell)', $segs[0]->describe());
        $this->assertSame("\x07", $segs[0]->raw());
    }

    public function testC0BackspaceIsolated(): void
    {
        $segs = Inspector::parse("\x08");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('BS (backspace)', $segs[0]->describe());
        $this->assertSame("\x08", $segs[0]->raw());
    }

    public function testC0NewlineIsolatesText(): void
    {
        // LF (0x0A) in text causes a flush - per step 10.16 spec.
        $segs = Inspector::parse("line1\nline2");
        $this->assertCount(3, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('line1', $segs[0]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertStringContainsString('LF (line feed)', $segs[1]->describe());
        $this->assertInstanceOf(TextSegment::class, $segs[2]);
        $this->assertSame('line2', $segs[2]->describe());
    }

    public function testC0CarriageReturnIsolatesText(): void
    {
        // CR (0x0D) in text causes a flush - per step 10.16 spec.
        $segs = Inspector::parse("line1\rline2");
        $this->assertCount(3, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('line1', $segs[0]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertStringContainsString('CR (carriage return)', $segs[1]->describe());
        $this->assertInstanceOf(TextSegment::class, $segs[2]);
        $this->assertSame('line2', $segs[2]->describe());
    }

    public function testC0InTextWithOtherSequences(): void
    {
        // \x1b[31m = CSI foreground red
        // red = text
        // \x07 = BEL (C0 code - becomes SequenceSegment per step 10.16)
        // normal = text
        $segs = Inspector::parse("\x1b[31mred\x07normal");
        $this->assertCount(4, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('foreground red', $segs[0]->describe());
        $this->assertInstanceOf(TextSegment::class, $segs[1]);
        $this->assertSame('red', $segs[1]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[2]);
        $this->assertStringContainsString('BEL (bell)', $segs[2]->describe());
        $this->assertInstanceOf(TextSegment::class, $segs[3]);
        $this->assertSame('normal', $segs[3]->describe());
    }

    public function testAllC0CodesAreRecognized(): void
    {
        // Test a few key C0 codes.
        $codes = [
            0x00 => 'NUL (null)',
            0x01 => 'SOH (start of heading)',
            0x02 => 'STX (start of text)',
            0x03 => 'ETX (end of text)',
            0x04 => 'EOT (end of transmission)',
            0x05 => 'ENQ (enquiry)',
            0x06 => 'ACK (acknowledge)',
            0x07 => 'BEL (bell)',
            0x08 => 'BS (backspace)',
            0x09 => 'HT (horizontal tab)',
            0x0A => 'LF (line feed)',
            0x0B => 'VT (vertical tab)',
            0x0C => 'FF (form feed)',
            0x0D => 'CR (carriage return)',
            0x0E => 'SO (shift out)',
            0x0F => 'SI (shift in)',
        ];
        foreach ($codes as $byte => $expected) {
            $segs = Inspector::parse(chr($byte));
            $this->assertCount(1, $segs, "C0 0x" . dechex($byte) . ' should produce one segment');
            $this->assertInstanceOf(SequenceSegment::class, $segs[0], "C0 0x" . dechex($byte) . ' should produce SequenceSegment');
            $this->assertStringContainsString($expected, $segs[0]->describe());
        }
    }

    public function testUpperHalfC0CodesAreRecognized(): void
    {
        // The sibling sweep above stops at 0x0F; the 0x10-0x17, 0x19 and
        // 0x1C-0x1F half rides the same execute() leg. ESC (0x1B) is the one
        // byte that must NOT come through it — see the pin below.
        $codes = [
            0x10 => 'DLE (data link escape)',
            0x11 => 'DC1 (device control 1)',
            0x12 => 'DC2 (device control 2)',
            0x13 => 'DC3 (device control 3)',
            0x14 => 'DC4 (device control 4)',
            0x15 => 'NAK (negative acknowledge)',
            0x16 => 'SYN (synchronous idle)',
            0x17 => 'ETB (end of transmission block)',
            0x18 => 'CAN (cancel)',
            0x19 => 'EM (end of medium)',
            0x1A => 'SUB (substitute)',
            0x1C => 'FS (file separator)',
            0x1D => 'GS (group separator)',
            0x1E => 'RS (record separator)',
            0x1F => 'US (unit separator)',
        ];
        foreach ($codes as $byte => $expected) {
            $segs = Inspector::parse('a' . chr($byte) . 'b');
            $this->assertCount(3, $segs, "C0 0x" . dechex($byte) . ' should isolate the text around it');
            $this->assertStringContainsString($expected, $segs[1]->describe(), "C0 0x" . dechex($byte) . ' label');
            $this->assertSame(chr($byte), $segs[1]->raw());
        }
    }

    public function testEscapeIsAnIntroducerNotAC0Segment(): void
    {
        // AnsiHandler::execute() guards `$byte !== 0x1B` on the C0 branch:
        // a lone ESC is the opening of an escape sequence (truncated at end
        // of stream), never a "C0 ESC" control report.
        $segs = Inspector::parse("\x1b");
        $this->assertCount(1, $segs);
        $this->assertSame("\x1b", $segs[0]->raw());
        $this->assertStringNotContainsString('C0 ESC', $segs[0]->describe());
    }

    public function testDelByteIsReportedNotSwallowed(): void
    {
        // DEL (0x7F) is part of the ISO 6429 C0 set although it sits outside
        // the 0x00-0x1F range; before the step 10.16 fix it reached
        // execute(), matched no branch, and the byte vanished — an
        // unlisted loss under the README fidelity contract.
        $segs = Inspector::parse("ab\x7fcd");
        $this->assertCount(3, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[1]);
        $this->assertSame("\x7f", $segs[1]->raw());
        $this->assertStringContainsString('DEL (delete)', $segs[1]->describe());
    }
}
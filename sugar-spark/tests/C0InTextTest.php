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
}
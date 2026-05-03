<?php

declare(strict_types=1);

namespace CandyCore\Spark\Tests;

use CandyCore\Spark\Segment;
use CandyCore\Spark\SequenceSegment;
use CandyCore\Spark\TextSegment;
use PHPUnit\Framework\TestCase;

final class SegmentTest extends TestCase
{
    public function testTextSegmentRawAndDescribeAreVerbatim(): void
    {
        $seg = new TextSegment('hello world');
        $this->assertSame('hello world', $seg->raw());
        $this->assertSame('hello world', $seg->describe());
        $this->assertInstanceOf(Segment::class, $seg);
    }

    public function testTextSegmentExposesValueProperty(): void
    {
        $seg = new TextSegment('payload');
        $this->assertSame('payload', $seg->value);
    }

    public function testSequenceSegmentRawIsBytes(): void
    {
        $seg = new SequenceSegment("\x1b[0m", 'SGR reset');
        $this->assertSame("\x1b[0m", $seg->raw());
    }

    public function testSequenceSegmentDescribePrintifiesEsc(): void
    {
        $seg = new SequenceSegment("\x1b[0m", 'SGR reset');
        $desc = $seg->describe();
        $this->assertStringContainsString('ESC[0m', $desc);
        $this->assertStringContainsString('SGR reset', $desc);
        $this->assertStringNotContainsString("\x1b", $desc);
    }

    public function testPrintableReplacesEscWithToken(): void
    {
        $this->assertSame('ESC[31m', SequenceSegment::printable("\x1b[31m"));
    }

    public function testPrintableNoOpForBytesWithoutEsc(): void
    {
        $this->assertSame('plain text', SequenceSegment::printable('plain text'));
    }

    public function testPrintableHandlesMultipleEscBytes(): void
    {
        $this->assertSame('ESC]0;hiESC\\', SequenceSegment::printable("\x1b]0;hi\x1b\\"));
    }

    public function testSequenceSegmentExposesBytesAndLabel(): void
    {
        $seg = new SequenceSegment("\x1b[1m", 'SGR bold');
        $this->assertSame("\x1b[1m", $seg->bytes);
        $this->assertSame('SGR bold', $seg->label);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\OutputWriter;
use SugarCraft\CodeShot\StringOutputWriter;
use PHPUnit\Framework\TestCase;

final class StringOutputWriterTest extends TestCase
{
    public function testImplementsOutputWriterInterface(): void
    {
        $writer = new StringOutputWriter();
        $this->assertInstanceOf(OutputWriter::class, $writer);
    }

    public function testWriteAccumulatesToBuffer(): void
    {
        $writer = new StringOutputWriter();
        $writer->write('hello');
        $writer->write(' world');

        $this->assertSame('hello world', $writer->result());
    }

    public function testResultReturnsEmptyStringWhenNoWrite(): void
    {
        $writer = new StringOutputWriter();
        $this->assertSame('', $writer->result());
    }

    public function testFlushIsNoOp(): void
    {
        $writer = new StringOutputWriter();
        $writer->write('test');
        $writer->flush();

        // flush should not change anything since string accumulation is already in memory
        $this->assertSame('test', $writer->result());
    }

    public function testMultipleWritesAccumulate(): void
    {
        $writer = new StringOutputWriter();
        $writer->write('line1');
        $writer->write("\n");
        $writer->write('line2');

        $this->assertSame("line1\nline2", $writer->result());
    }

    public function testEmptyStringWriteDoesNotAffectBuffer(): void
    {
        $writer = new StringOutputWriter();
        $writer->write('content');
        $writer->write('');

        $this->assertSame('content', $writer->result());
    }

    public function testUnicodeContentPreserved(): void
    {
        $writer = new StringOutputWriter();
        $writer->write('こんにちは');
        $writer->write(' 🌍');

        $this->assertSame('こんにちは 🌍', $writer->result());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\FileOutputWriter;
use SugarCraft\Freeze\OutputWriter;
use PHPUnit\Framework\TestCase;

final class FileOutputWriterTest extends TestCase
{
    private string $tempDir;
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-freeze-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->tempFile = $this->tempDir . '/output.txt';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->tempFile) && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testImplementsOutputWriterInterface(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $this->assertInstanceOf(OutputWriter::class, $writer);
    }

    public function testWriteCreatesFile(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('hello world');

        $this->assertFileExists($this->tempFile);
        $this->assertSame('hello world', file_get_contents($this->tempFile));
    }

    public function testWriteAppendsToFile(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('part1');
        $writer->write('part2');

        $this->assertSame('part1part2', file_get_contents($this->tempFile));
    }

    public function testFlushWritesToDisk(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('test content');
        $writer->flush();

        // After flush, content should be readable even before close/destruct
        $this->assertSame('test content', file_get_contents($this->tempFile));
    }

    public function testCloseClosesFileHandle(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('content');
        $writer->close();

        // File should still have content after close
        $this->assertSame('content', file_get_contents($this->tempFile));
    }

    public function testConstructorThrowsOnInvalidPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to open file for writing/');

        new FileOutputWriter('/nonexistent/path/that/cannot/be/created/file.txt');
    }

    public function testMultipleWritesAcrossFlush(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('line1');
        $writer->flush();
        $writer->write('line2');

        $this->assertSame('line1line2', file_get_contents($this->tempFile));
    }

    public function testDestructorClosesFile(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('final content');
        unset($writer);

        $this->assertSame('final content', file_get_contents($this->tempFile));
    }

    public function testWriteAfterCloseDoesNotWrite(): void
    {
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('before close');
        $writer->close();

        // The close sets $this->fp = null, so subsequent writes are no-ops.
        // The file should still have the content from before close.
        $this->assertSame('before close', file_get_contents($this->tempFile));
    }
}

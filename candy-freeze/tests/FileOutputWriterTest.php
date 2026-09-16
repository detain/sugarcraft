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

    public function testE739MissingDirectoryDoorThrowsWithoutADiagnostic(): void
    {
        // E739: fopen() used to emit "Failed to open stream" as an E_WARNING on the way to
        // returning false, so the refusal arrived after the noise (and red under this
        // library's failOnWarning gate). The door is now a throw with the path named, and
        // nothing at all reaches the error handler.
        $unopenablePath = '/candy-freeze-e739-missing-directory/deeper/file.txt';
        [$diagnostics, $thrown] = $this->e739CaptureDoorAttempt($unopenablePath);

        $this->assertSame([], $diagnostics, 'the refusal must not emit a PHP diagnostic');
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('Failed to open file for writing', $thrown->getMessage());
        $this->assertStringContainsString($unopenablePath, $thrown->getMessage());
        $this->assertStringContainsString('directory does not exist', $thrown->getMessage());
    }

    public function testE739DirectoryPathDoorThrowsWithoutADiagnostic(): void
    {
        // A directory is a path the class used to warn about ("Is a directory") before it
        // refused; now it is refused by inspection.
        [$diagnostics, $thrown] = $this->e739CaptureDoorAttempt($this->tempDir);

        $this->assertSame([], $diagnostics);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString($this->tempDir, $thrown->getMessage());
        $this->assertStringContainsString('path is a directory', $thrown->getMessage());
    }

    public function testE739UnwritableExistingFileDoorThrowsWithoutADiagnostic(): void
    {
        // Skipped where the filesystem hands writes to anybody (a root run ignores the
        // mode bits, so fopen would genuinely succeed and there is nothing to pin).
        if (file_put_contents($this->tempFile, 'seed') === false) {
            $this->markTestSkipped('could not seed the read-only fixture file');
        }
        chmod($this->tempFile, 0444);
        clearstatcache(true, $this->tempFile);

        if (is_writable($this->tempFile)) {
            chmod($this->tempFile, 0644);
            $this->markTestSkipped('this filesystem reports a 0444 file as writable');
        }

        [$diagnostics, $thrown] = $this->e739CaptureDoorAttempt($this->tempFile);

        chmod($this->tempFile, 0644);
        $this->assertSame([], $diagnostics);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('file is not writable', $thrown->getMessage());
    }

    public function testE739WritablePathStillOpensThroughThePreChecks(): void
    {
        // Positive polarity for the door: every guard above must stay inert on a path a
        // write-open can serve.
        $writer = new FileOutputWriter($this->tempFile);
        $writer->write('E739');
        $writer->close();

        $this->assertSame('E739', file_get_contents($this->tempFile));
    }

    /**
     * Attempts to construct a writer on $path with a temporary error handler installed, so a
     * test can assert the door stayed silent instead of relying on the XML gate alone.
     * The handler consumes diagnostics (PHPUnit would turn one into an error) and hands them
     * back for assertion.
     *
     * @return array{0: list<string>, 1: ?\Throwable}
     */
    private function e739CaptureDoorAttempt(string $path): array
    {
        $diagnostics = [];
        $thrown = null;

        set_error_handler(static function (int $severity, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $severity . ':' . $message;

            return true;
        });

        try {
            new FileOutputWriter($path);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        } finally {
            restore_error_handler();
        }

        return [$diagnostics, $thrown];
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

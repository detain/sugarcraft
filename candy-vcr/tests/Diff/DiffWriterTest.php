<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Diff;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Diff\DiffWriter;

/**
 * @covers \SugarCraft\Vcr\Diff\DiffWriter
 */
final class DiffWriterTest extends TestCase
{
    private DiffWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new DiffWriter();
    }

    public function testWriteUnifiedDiffCreatesFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $expected = "hello\nworld\n";
        $actual = "hello\nworld\n";

        $result = $this->writer->writeUnifiedDiff($path, $expected, $actual);

        $this->assertNotFalse($result);
        $content = file_get_contents($path);
        $this->assertStringContainsString('---', $content);
        $this->assertStringContainsString('+++', $content);
        unlink($path);
    }

    public function testUnifiedDiffShowsChanges(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $expected = "hello\nworld\n";
        $actual = "hello\nuniverse\n";

        $this->writer->writeUnifiedDiff($path, $expected, $actual);

        $content = file_get_contents($path);
        $this->assertStringContainsString('-world', $content);
        $this->assertStringContainsString('+universe', $content);
        unlink($path);
    }

    public function testUnifiedDiffIdenticalFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $content = "hello\nworld\n";
        $result = $this->writer->writeUnifiedDiff($path, $content, $content);

        $this->assertNotFalse($result);
        $output = file_get_contents($path);
        $this->assertStringContainsString('(no differences)', $output);
        unlink($path);
    }

    public function testBuildUnifiedDiffReturnsString(): void
    {
        $diff = $this->writer->buildUnifiedDiff("a\nb\n", "a\nc\n");
        $this->assertIsString($diff);
        $this->assertStringContainsString('---', $diff);
        $this->assertStringContainsString('+++', $diff);
    }

    public function testBuildUnifiedDiffWithMultipleChanges(): void
    {
        $expected = "line1\nline2\nline3\nline4\nline5\n";
        $actual = "line1\nmodified2\nline3\nmodified4\nline5\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('+modified2', $diff);
        $this->assertStringContainsString('-line4', $diff);
        $this->assertStringContainsString('+modified4', $diff);
    }

    public function testBuildUnifiedDiffNormalizesLineEndings(): void
    {
        $expected = "line1\r\nline2\r\n";
        $actual = "line1\nline2\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        // Should not report differences due to line ending normalization
        $this->assertStringContainsString('(no differences)', $diff);
    }

    public function testWriteUnifiedDiffReturnsFalseOnFailure(): void
    {
        // Try to write to an invalid path
        $result = $this->writer->writeUnifiedDiff('/nonexistent/directory/file.diff', 'a', 'b');
        $this->assertFalse($result);
    }

    public function testBuildUnifiedDiffEmptyFiles(): void
    {
        $diff = $this->writer->buildUnifiedDiff('', '');
        $this->assertStringContainsString('(no differences)', $diff);
    }

    public function testBuildUnifiedDiffSingleLineChange(): void
    {
        $diff = $this->writer->buildUnifiedDiff("old\n", "new\n");
        $this->assertStringContainsString('-old', $diff);
        $this->assertStringContainsString('+new', $diff);
    }

    public function testBuildUnifiedDiffAddedLines(): void
    {
        $expected = "line1\n";
        $actual = "line1\nline2\nline3\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('+line2', $diff);
        $this->assertStringContainsString('+line3', $diff);
    }

    public function testBuildUnifiedDiffRemovedLines(): void
    {
        $expected = "line1\nline2\nline3\n";
        $actual = "line1\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('-line3', $diff);
    }

    public function testWriteAnsiDiffDoesNotThrow(): void
    {
        $expected = "hello\nworld\n";
        $actual = "hello\nuniverse\n";

        // Should not throw, just write to output
        $this->writer->writeAnsiDiff($expected, $actual, fopen('php://memory', 'w'));
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testBuildUnifiedDiffWithBinaryContent(): void
    {
        $expected = "\x00\x01\x02\n";
        $actual = "\x00\x01\x03\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        // Binary content should be escaped (null byte becomes \0 in output)
        $this->assertStringContainsString('\\0', $diff);
        $this->assertStringContainsString('-', $diff);
        $this->assertStringContainsString('+', $diff);
    }
}

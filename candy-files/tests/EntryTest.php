<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\Entry;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    public function testParentSentinelIsRecognised(): void
    {
        $this->assertTrue(Entry::parent()->isParentSentinel());
        $this->assertFalse((new Entry('foo', true, 0, 0))->isParentSentinel());
    }

    public function testDisplaySizeForDirectory(): void
    {
        $this->assertSame('DIR', (new Entry('a', true, 12345, 0))->displaySize());
    }

    public function testDisplaySizeForLink(): void
    {
        $this->assertSame('LINK', (new Entry('a', false, 1234, 0, isLink: true))->displaySize());
    }

    public function testDisplaySizeBytes(): void
    {
        $this->assertSame('512B', (new Entry('a', false, 512, 0))->displaySize());
    }

    public function testDisplaySizeKilobytes(): void
    {
        $this->assertSame('2.0KB', (new Entry('a', false, 2048, 0))->displaySize());
    }

    public function testDisplaySizeMegabytes(): void
    {
        $this->assertSame('1.5MB', (new Entry('a', false, 1024 * 1024 * 3 / 2, 0))->displaySize());
    }

    public function testSanitizeNameRemovesAnsiEscapeSequences(): void
    {
        // C0 control characters should be stripped
        $this->assertSame('hello', Entry::sanitizeName("hello\x1b[31m"));
        $this->assertSame('hello', Entry::sanitizeName("hello\x1b[0m"));
        $this->assertSame('test', Entry::sanitizeName("test\x07")); // bell character

        // DEL character should be stripped
        $this->assertSame('hello', Entry::sanitizeName("hello\x7f"));

        // C1 control characters (bytes 0x80-0x9F) should be stripped
        $this->assertSame('hello', Entry::sanitizeName("hello\xc2\x80")); // U+0080
        $this->assertSame('hello', Entry::sanitizeName("hello\xc2\x9f")); // U+009F

        // Normal filenames pass through unchanged
        $this->assertSame('normal_file.txt', Entry::sanitizeName('normal_file.txt'));
        $this->assertSame('日本語', Entry::sanitizeName('日本語'));
    }

    public function testSanitizeNameStripsControlBytesOnly(): void
    {
        // Only control bytes should be removed, regular bytes preserved
        $input = "file\x00name\x01with\x1fcontrols";
        $output = Entry::sanitizeName($input);
        $this->assertSame('filenamewithcontrols', $output);
    }
}

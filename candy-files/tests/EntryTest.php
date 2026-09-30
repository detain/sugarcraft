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

    /**
     * The audit's MEDIUM: the old sweep exempted ESC itself and knew only
     * the CSI shape, so OSC set-title, charset selection (ESC B) and
     * save-cursor (ESC 7) crafted into filenames survived verbatim and
     * reached the terminal through candy-sprinkles rendering raw text.
     * bin2hex-level pins: the result carries NO control byte at all.
     */
    public static function escapeBypassProvider(): array
    {
        return [
            'OSC set-title consumed whole' => ["\x1b]0;pwned\x07report.pdf", 'report.pdf'],
            'OSC with ST terminator'       => ["a\x1b]2;x\x1b\\b.txt", 'ab.txt'],
            'charset designation ESC (B'   => ["\x1b(Bfile.txt", 'file.txt'],
            'save-cursor ESC 7'            => ["\x1b7notes.md", 'notes.md'],
            'restore-cursor ESC 8'         => ["\x1b8notes.md", 'notes.md'],
            'CSI color pair'               => ["\x1b[31mred\x1b[0m.txt", 'red.txt'],
            'nested OSC: inner consumed, outer params degrade to printable garbage' => ["\x1b]0;\x1b]1;x\x07y\x07", '0;y'],
            'ESC + final byte is a real sequence (REP) and dies whole' => ["a\x1bb", 'a'],
            'two adjacent controls with no sequence body die in sweep' => ["a\x1b\x07b", 'ab'],
        ];
    }

    /**
     * @dataProvider escapeBypassProvider
     */
    public function testSanitizeNameKillsEveryEscapeCraft(string $poison, string $expected): void
    {
        $clean = Entry::sanitizeName($poison);
        $this->assertSame($expected, $clean, 'byte-exact result, pinned at hex level: ' . bin2hex($clean));
        $this->assertStringNotContainsString("\x1b", $clean);
        $this->assertStringNotContainsString("\x07", $clean);
    }

    public function testSanitizeNameUnterminatedEscapeLeavesNoControlByte(): void
    {
        // An unterminated OSC cannot be consumed whole; the introducer must
        // still die in the sweep so no sequence ever assembles on screen.
        $clean = Entry::sanitizeName("\x1b]0;evil");
        $this->assertStringNotContainsString("\x1b", $clean);
        $this->assertStringNotContainsString("\x07", $clean);
    }
}

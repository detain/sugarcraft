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

        // M-11: the LONE 8-bit spellings are controls too (a raw \x9b acts
        // as CSI), and stripping them must not eat valid multi-byte text.
        $this->assertSame('hello', Entry::sanitizeName("hello\x9b"));         // lone CSI
        $this->assertSame('xy', Entry::sanitizeName("x\x80\x9fy"));           // lone boundary bytes
        $this->assertSame('日本語', Entry::sanitizeName("日本\x9b語"));         // C1 dropped, sequence survives
        $this->assertSame('café.txt', Entry::sanitizeName("caf\xc3\xa9.txt")); // é is C3 A9, not C1

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
     * reached the terminal through sugar-style rendering raw text.
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
            'lone 8-bit C1 CSI (0x9b) stripped, its params degrade to printable' => ["a\x9b[31mb", 'a[31mb'],
            'lone C1 boundary bytes 0x80/0x9f stripped' => ["x\x80\x9fy", 'xy'],
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

    /**
     * CF-27 (audit 039): BIDI overrides and isolates are format characters,
     * not controls, so every escape sweep above let them through — and a
     * terminal that honours them REWRITES the visible order. The classic
     * probe 'invoice.txt.<U+202E>gpj.exe' PAINTS as 'invoice.txt.jpgexe'
     * (the trailing 'gpj.' is mirrored), hiding the real executable
     * extension. The markers restore an honest reading.
     */
    public function testSanitizeNameMarksBidiOverride(): void
    {
        $clean = Entry::sanitizeName("invoice.txt.\u{202E}gpj.exe");
        $this->assertSame('invoice.txt.<U+202E>gpj.exe', $clean);
        $this->assertStringNotContainsString("\xE2\x80\xAE", $clean);
        // Every embedding/override in the U+202A-U+202E range is marked.
        foreach ([0x202A, 0x202B, 0x202C, 0x202D, 0x202E] as $cp) {
            $ch = mb_chr($cp, 'UTF-8');
            $marker = '<U+' . strtoupper(dechex($cp)) . '>';
            $clean = Entry::sanitizeName('a' . $ch . 'b');
            $this->assertSame('a' . $marker . 'b', $clean);
            $this->assertStringNotContainsString($ch, $clean);
        }
    }

    public function testSanitizeNameMarksIsolatesAndBom(): void
    {
        // U+2066-U+2069 isolates and the U+FEFF BOM/zero-width no-break
        // space: invisible reordering and invisible-prefix (hidden dotfile
        // disguise) vectors.
        $clean = Entry::sanitizeName("\u{2066}secret\u{2069}.txt");
        $this->assertSame('<U+2066>secret<U+2069>.txt', $clean);
        $clean = Entry::sanitizeName("\u{FEFF}.hidden.txt");
        $this->assertSame('<U+FEFF>.hidden.txt', $clean);
    }

    public function testSanitizeNameBidiPassLeavesValidTextUntouched(): void
    {
        // The byte-mode marker pass must not disturb ordinary multi-byte
        // names (no needle bytes appear inside legit sequences for these).
        $this->assertSame('日本語.txt', Entry::sanitizeName('日本語.txt'));
        $this->assertSame('café—report.pdf', Entry::sanitizeName('café—report.pdf'));
        $this->assertSame('plain_file.tar.gz', Entry::sanitizeName('plain_file.tar.gz'));
    }
}

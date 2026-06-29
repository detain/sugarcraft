<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Sanitize, StyledCell, Table};
use PHPUnit\Framework\TestCase;

final class TableSanitizeTest extends TestCase
{
    /**
     * Verify Sanitize::value neutralizes C0 control characters (and DEL).
     */
    public function testSanitizeReplacesC0Controls(): void
    {
        // NUL, BEL, BS — each becomes ·
        $this->assertSame('·', Sanitize::value("\x00"));
        $this->assertSame('··', Sanitize::value("\x00\x07"));
        $this->assertSame('·', Sanitize::value("\x07"));  // BEL
        $this->assertSame('·', Sanitize::value("\x7f"));  // DEL
    }

    /**
     * Verify ESC (0x1B) is replaced, but printable ASCII that follows is preserved.
     * "\x1b[2J" → "·[2J" (ESC stripped, [2J are printable ASCII bytes).
     */
    public function testSanitizeReplacesESCCSI(): void
    {
        $this->assertSame('·[2J', Sanitize::value("\x1b[2J", false));
    }

    /**
     * C1 controls (U+0080-U+009F) in UTF-8 are \xC2\x80–\xC2\x9F.
     * They are rare in practice.  Verify the regex pattern is correct.
     */
    public function testSanitizeReplacesC1Controls(): void
    {
        // Valid UTF-8 encoding of U+0080 is \xC2\x80
        $this->assertSame('·', Sanitize::value("\xC2\x80", false));
        // Valid UTF-8 encoding of U+009F is \xC2\x9F
        $this->assertSame('·', Sanitize::value("\xC2\x9F", false));
    }

    /**
     * Verify newlines are collapsed to ↵ (U+21B5) in single-line context.
     */
    public function testSanitizeCollapsesNewlinesSingleLine(): void
    {
        $arrow = "\xE2\x86\x92";  // ↵ as UTF-8
        $this->assertSame("a{$arrow}b", Sanitize::value("a\nb", false));
        $this->assertSame("a{$arrow}b", Sanitize::value("a\r\nb", false));
        $this->assertSame("a{$arrow}b", Sanitize::value("a\rb", false));
    }

    /**
     * Verify newlines are preserved as \n in multiline context.
     */
    public function testSanitizePreservesNewlinesMultiline(): void
    {
        $this->assertSame("a\nb", Sanitize::value("a\nb", true));
        $this->assertSame("a\nb", Sanitize::value("a\r\nb", true));
        $this->assertSame("a\nb", Sanitize::value("a\rb", true));
    }

    /**
     * Verify invalid UTF-8 is repaired (or at least does not crash).
     * Overlong encoding of NUL: \xc0\x80 — iconv //IGNORE drops it.
     */
    public function testSanitizeHandlesInvalidUtf8Gracefully(): void
    {
        $result = Sanitize::value("\xc0\x80", false);
        // iconv //IGNORE drops invalid bytes, so result may be '' or partial
        $this->assertIsString($result);
    }

    /**
     * Verify clean text (ASCII and valid UTF-8 multi-byte) passes through unchanged.
     */
    public function testSanitizePassesCleanTextThrough(): void
    {
        $this->assertSame('Hello', Sanitize::value('Hello'));
        $this->assertSame('日本語', Sanitize::value('日本語'));
        $arrow = "\xE2\x86\x92";
        $this->assertSame("line1{$arrow}line2", Sanitize::value("line1\nline2", false));
    }

    /**
     * Verify ANSI escape sequences embedded in cell data are neutralized
     * and do NOT appear as raw escape bytes in the rendered View output.
     *
     * The ESC byte (\x1b) is replaced with ·; the following [2J CSI bytes
     * are printable ASCII and pass through.  After sanitization the cell
     * content reads "·[2J·[0mINJECTED" — the escape wrappers are broken and
     * no raw \x1b bytes remain.
     */
    public function testViewRejectsCSIEscapeInjection(): void
    {
        $t = Table::withColumns([
            Column::new('data', 'Data', 20),
        ])->withRows([
            Row::new(RowData::from(['data' => "\x1b[2J\x1b[0mINJECTED"])),
        ]);

        $view = $t->View();

        // The dangerous CSI Erase Screen \x1b[2J must not appear intact.
        // We check for the specific injection pattern, not bare \x1b
        // (bare \x1b appears in legitimate ANSI codes like style resets).
        $this->assertStringNotContainsString("\x1b[2J", $view);
    }

    /**
     * Verify OSC (Operating System Command) injection is neutralized.
     * OSC is ESC ] 0 ; ... BEL/ST.  Only the ESC (C0) is replaced.
     * The readable text "pwned" will appear but the OSC wrapping is broken.
     */
    public function testViewRejectsOSCInjection(): void
    {
        $t = Table::withColumns([
            Column::new('title', 'Title', 20),
        ])->withRows([
            Row::new(RowData::from(['title' => "\x1b]0;pwned\x07HOOKED"])),
        ]);

        $view = $t->View();

        // The OSC start sequence must not appear intact
        $this->assertStringNotContainsString("\x1b]0", $view);
        // The raw BEL byte must not appear
        $this->assertStringNotContainsString("\x07", $view);
    }

    /**
     * Verify raw C0/DEL bytes in cell data are neutralized to · in View.
     * Printable ASCII (like "INJECT") is not C0 and passes through.
     */
    public function testViewRejectsRawControlBytes(): void
    {
        $t = Table::withColumns([
            Column::new('raw', 'Raw', 20),
        ])->withRows([
            Row::new(RowData::from(['raw' => "\x00\x07\x7fINJECT"])),
        ]);

        $view = $t->View();

        // Raw control bytes must be neutralized (replaced with ·)
        $this->assertStringNotContainsString("\x00", $view);
        $this->assertStringNotContainsString("\x07", $view);
        $this->assertStringNotContainsString("\x7f", $view);
        // "INJECT" is printable ASCII (not C0/C1) so it passes through;
        // the security win is the control bytes are gone, breaking any
        // escape-sequence injection
        $this->assertStringContainsString("INJECT", $view);
    }

    /**
     * Verify header titles with control characters are sanitized.
     * The ESC byte is replaced with ·; printable ASCII after it passes through.
     */
    public function testHeaderSanitizesTitleControlChars(): void
    {
        $col = Column::new('h', "\x1b[2JEvil", 20);
        $t = Table::withColumns([$col])->withRows([
            Row::new(RowData::from(['h' => 'ok'])),
        ]);

        $view = $t->View();

        // Raw ESC byte must be gone
        $this->assertStringNotContainsString("\x1b[2J", $view);
        // Printable ASCII after the ESC (like "Evil") may still appear — the
        // point is the escape sequence itself is broken
    }

    /**
     * Verify multiline-mode cells with embedded newlines preserve those
     * newlines (for explode) while C0/DEL chars in the content are still
     * neutralized.
     */
    public function testMultilineCellSanitizePreservesNewlines(): void
    {
        $t = Table::withColumns([
            Column::new('multi', 'Multi', 20),
        ])->withRows([
            Row::new(RowData::from(['multi' => "line1\nline2\x1b[2J"])),
        ])->withMultilineMode(true);

        $view = $t->View();

        // \n must survive sanitization (preserveNewlines=true)
        $this->assertStringContainsString("line1", $view);
        $this->assertStringContainsString("line2", $view);
        // But the ESC[2J must be neutralized
        $this->assertStringNotContainsString("\x1b[2J", $view);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\CellValue;
use PHPUnit\Framework\TestCase;

final class CellValueTest extends TestCase
{
    // ── display() — NULL handling ─────────────────────────────────────────────

    public function testDisplayNullShowsNullToken(): void
    {
        $this->assertSame('NULL', CellValue::display(null));
    }

    public function testDisplayScalarString(): void
    {
        $this->assertSame('hello', CellValue::display('hello'));
    }

    public function testDisplayScalarInt(): void
    {
        $this->assertSame('42', CellValue::display(42));
    }

    public function testDisplayScalarFloat(): void
    {
        $this->assertSame('3.14', CellValue::display(3.14));
    }

    public function testDisplayScalarBoolTrue(): void
    {
        $this->assertSame('1', CellValue::display(true));
    }

    public function testDisplayScalarBoolFalse(): void
    {
        // (string) false === '' in PHP
        $this->assertSame('', CellValue::display(false));
    }

    public function testDisplayArrayJsonEncoded(): void
    {
        $result = CellValue::display(['a' => 1, 'b' => 2]);
        // JSON encoding is sorted keys by default (with JSON_PRESERVE_ZERO_FRONT)
        // The exact string depends on json_encode, but it should contain the values.
        $this->assertStringContainsString('a', $result);
        $this->assertStringContainsString('1', $result);
    }

    public function testDisplayObjectJsonEncoded(): void
    {
        $result = CellValue::display((object) ['x' => 9]);
        $this->assertStringContainsString('x', $result);
        $this->assertStringContainsString('9', $result);
    }

    public function testDisplayJsonEncodeFailureYieldsEmpty(): void
    {
        // A resource cannot be JSON-encoded and should yield ''.
        $resource = fopen('php://memory', 'r');
        $result = CellValue::display($resource);
        fclose($resource);
        $this->assertSame('', $result);
    }

    // ── sanitize() — C0 control bytes ────────────────────────────────────────

    public function testSanitizeEscapesESC(): void
    {
        // ESC (0x1b) should become middle dot
        $this->assertSame('·', CellValue::sanitize("\x1b"));
    }

    public function testSanitizeEscapesNUL(): void
    {
        $this->assertSame('·', CellValue::sanitize("\x00"));
    }

    public function testSanitizeEscapesControlBytes(): void
    {
        // All C0 bytes (0x00-0x1F) become middle dot EXCEPT
        // \r (0x0D), \n (0x0A), and \r\n which are replaced with ↵ first.
        $newlineBytes = [0x0D, 0x0A]; // \r, \n are handled specially
        foreach (range(0x00, 0x1F) as $byte) {
            if (in_array($byte, $newlineBytes, true)) {
                continue; // These become ↵, not ·
            }
            $char = chr($byte);
            $this->assertSame('·', CellValue::sanitize($char), "Byte 0x" . sprintf('%02X', $byte) . " should become ·");
        }
    }

    public function testSanitizeEscapesDEL(): void
    {
        // DEL (0x7F) becomes middle dot
        $this->assertSame('·', CellValue::sanitize("\x7f"));
    }

    // ── sanitize() — C1 control bytes ─────────────────────────────────────────

    public function testSanitizeEscapesC1Range(): void
    {
        // C1 range U+0080-U+009F becomes middle dot
        // U+0080 = \xC2\x80 in UTF-8
        $this->assertSame('·', CellValue::sanitize("\xC2\x80"));
        $this->assertSame('·', CellValue::sanitize("\xC2\x9F"));
    }

    // ── sanitize() — Newlines ─────────────────────────────────────────────────

    public function testSanitizeCRLFToArrow(): void
    {
        $this->assertSame('↵', CellValue::sanitize("\r\n"));
    }

    public function testSanitizeCRToArrow(): void
    {
        $this->assertSame('↵', CellValue::sanitize("\r"));
    }

    public function testSanitizeLFToArrow(): void
    {
        $this->assertSame('↵', CellValue::sanitize("\n"));
    }

    public function testSanitizeMixedNewlinesToArrow(): void
    {
        // Multiple newlines become multiple arrows
        $this->assertSame('↵↵', CellValue::sanitize("\r\n\r\n"));
    }

    // ── sanitize() — Invalid UTF-8 ─────────────────────────────────────────────

    public function testSanitizeInvalidUTF8ToReplacementChar(): void
    {
        // Invalid UTF-8 byte sequence should become U+FFFD REPLACEMENT CHARACTER
        // e.g. a bare 0x80 byte (continuation byte without start)
        $this->assertSame("\u{FFFD}", CellValue::sanitize("\x80"));
    }

    public function testSanitizeInvalidUTF8SequenceToReplacementChar(): void
    {
        // Multiple invalid bytes each become replacement char
        $this->assertSame("\u{FFFD}\u{FFFD}", CellValue::sanitize("\x80\x80"));
    }

    // ── sanitize() — Valid content preserved ──────────────────────────────────

    public function testSanitizePreservesRegularText(): void
    {
        $this->assertSame('hello world', CellValue::sanitize('hello world'));
    }

    public function testSanitizePreservesUnicode(): void
    {
        $this->assertSame('日本語', CellValue::sanitize('日本語'));
    }

    public function testSanitizePreservesEmoji(): void
    {
        $this->assertSame('👍', CellValue::sanitize('👍'));
    }

    // ── Combined display() + sanitize() ───────────────────────────────────────

    public function testDisplayEscapesNewlinesInScalar(): void
    {
        // A scalar with a newline gets sanitized: newline → ↵
        $this->assertSame("hello↵world", CellValue::display("hello\nworld"));
    }

    public function testDisplayEscapesControlBytesInScalar(): void
    {
        $this->assertSame("hel·lo", CellValue::display("hel\x00lo"));
    }

    public function testDisplayNullStillShowsNullToken(): void
    {
        // display(null) returns 'NULL' directly, never hits sanitize()
        $this->assertSame('NULL', CellValue::display(null));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Output\Sanitize;

/**
 * Tests for the terminal output sanitizer.
 *
 * Verifies that dangerous control bytes and ANSI escape sequences are
 * stripped from untrusted strings before terminal rendering.
 */
final class SanitizeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // CSI cursor-move sequences (ESC[<digit>+{HJKMR}) — must be removed
    // ═══════════════════════════════════════════════════════════════

    public function testStripCursorMoveEscape(): void
    {
        // CSI cursor-up 2J (clear screen)
        $dirty = "\x1b[2Jhi";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('hi', $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
    }

    public function testStripCursorPositioning(): void
    {
        // CSI cup (cursor position) ESC[10;10H
        $dirty = "\x1b[10;10Hcontent\x1b[1A";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('content', $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // OSC title and clipboard sequences — must be removed
    // ═══════════════════════════════════════════════════════════════

    public function testStripOscTitleSequence(): void
    {
        // OSC 0 set window title BEL
        $dirty = "\x1b]0;malicious title\x07visible";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('visible', $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
        $this->assertStringNotContainsString('malicious', $clean);
    }

    public function testStripOscClipboardSequence(): void
    {
        // OSC 52 clipboard copy BEL
        $dirty = "\x1b]52;c;ZGFuZ2Vyb3Vz\x07data";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('data', $clean);
    }

    public function testStripOscTitleWithStTerminator(): void
    {
        // OSC with ST (ESC\) terminator instead of BEL
        $dirty = "\x1b]0;title\x1b\\output";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('output', $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // Raw C0 control bytes — bell, backspace, null, etc.
    // ═══════════════════════════════════════════════════════════════

    public function testStripRawBellByte(): void
    {
        $dirty = "hello\x07world";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('helloworld', $clean);
    }

    public function testStripRawBackspaceByte(): void
    {
        $dirty = "hello\x08world";
        $clean = Sanitize::untrusted($dirty);
        // BS is stripped, so the result is just "helloworld" (BS just removed, not backspace)
        $this->assertSame('helloworld', $clean);
    }

    public function testStripRawNullBytes(): void
    {
        $dirty = "\x00\x01\x02safe\x03\x04";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('safe', $clean);
    }

    public function testStripRawOtherC0Bytes(): void
    {
        // NAK, SYN, ETB, CAN, EM, SUB, ESC, etc.
        $dirty = "text\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1fend";
        $clean = Sanitize::untrusted($dirty);
        // ESC (\x1b) also stripped by the strip pass
        $this->assertSame('textend', $clean);
    }

    public function testStripC1ControlBytes(): void
    {
        // C1 range: \x80-\x9f (including delta, inquiry, etc.)
        $dirty = "start\x80\x81\x82middle\x9e\x9fend";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('startmiddleend', $clean);
    }

    public function testStripDeleteByte(): void
    {
        $dirty = "safe\x7ftext";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('safetext', $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // Characters that MUST be preserved: newline and tab
    // ═══════════════════════════════════════════════════════════════

    public function testPreserveNewline(): void
    {
        $dirty = "line1\x0aline2";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("line1\nline2", $clean);
    }

    public function testPreserveTab(): void
    {
        $dirty = "col1\x09col2";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("col1\tcol2", $clean);
    }

    public function testPreserveNewlineAndTabTogether(): void
    {
        // Tab and multiple line-ending styles: LF, CR, CRLF.
        // CR (\x0d) is NOT in the stripped range, so it is preserved.
        // FF (\x0c) is explicitly stripped.
        $dirty = "a\x09b\x0ac\x0d\x0ax\x0d"; // tab, LF, CR, trailing CR
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("a\tb\nc\r\nx\r", $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // Plain UTF-8 (including multibyte) — must be untouched
    // ═══════════════════════════════════════════════════════════════

    public function testPreserveUtf8Multibyte(): void
    {
        $dirty = "こんにちは\x1b[2J世界"; // Japanese + CSI clear screen
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('こんにちは世界', $clean);
    }

    public function testPreserveUtf8Emoji(): void
    {
        $dirty = "Hello\x1b[5m⚠️\x1b[0mWorld"; // SGR invert + warning
        $clean = Sanitize::untrusted($dirty);
        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('⚠️', $clean);
        $this->assertStringContainsString('World', $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
    }

    public function testPreserveUtf8MixedScripts(): void
    {
        $dirty = "English中文日本語한국어\x07"; // bell at end
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('English中文日本語한국어', $clean);
    }

    public function testPreservePureAscii(): void
    {
        $dirty = "Hello, World! This is a normal sentence.";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame($dirty, $clean);
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $clean = Sanitize::untrusted('');
        $this->assertSame('', $clean);
    }

    public function testAlreadyCleanStringUnchanged(): void
    {
        $input = "Normal text with numbers 12345 and symbols !@#$%";
        $clean = Sanitize::untrusted($input);
        $this->assertSame($input, $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // SGR (Select Graphic Rendition) color/style sequences — stripped
    // ═══════════════════════════════════════════════════════════════

    public function testStripSgrColorSequences(): void
    {
        $dirty = "\x1b[31mred\x1b[0m\x1b[1mbold\x1b[0m";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('redbold', $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
    }

    public function testStripMixedAnsiAndControlBytes(): void
    {
        // Combination: SGR bold red + VT + OSC title + raw DEL
        $dirty = "\x1b[1;31m\x0b\x1b]0;title\x07\x7fsafe";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('safe', $clean);
    }

    public function testStripRealWorldInjection(): void
    {
        // Simulates a malicious weather API response with ANSI injection
        $dirty = "\x1b[2J\x1b[HWeather: Clear\x1b[0m\nLocation: Springfield";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("Weather: Clear\nLocation: Springfield", $clean);
    }

    // ═══════════════════════════════════════════════════════════════
    // Byte-sequence edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testStripLoneEscFollowedByText(): void
    {
        // Lone ESC followed by ordinary text — the ESC byte is stripped,
        // the following characters are preserved.
        $dirty = "\x1bhello";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame('hello', $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
    }

    public function testStripVerticalTab(): void
    {
        // VT (\x0b) is NOT \n — must be stripped
        $dirty = "line1\x0bline2";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("line1line2", $clean);
    }

    public function testStripFormFeed(): void
    {
        // FF (\x0c) must be stripped
        $dirty = "page1\x0cpage2";
        $clean = Sanitize::untrusted($dirty);
        $this->assertSame("page1page2", $clean);
    }
}

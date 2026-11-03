<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Ansi;

final class AnsiTest extends TestCase
{
    private string|false $savedNoColor = false;

    protected function setUp(): void
    {
        // Hermetic: decorations under test must not depend on a host NO_COLOR.
        $this->savedNoColor = getenv('NO_COLOR');
        putenv('NO_COLOR');
    }

    protected function tearDown(): void
    {
        putenv($this->savedNoColor === false ? 'NO_COLOR' : 'NO_COLOR=' . $this->savedNoColor);
    }
    public function testWrapWithEmptyCodesReturnsTextUnchanged(): void
    {
        $result = Ansi::wrap('hello', '');
        $this->assertSame('hello', $result);
    }

    public function testWrapWithEmptyCodesReturnsEmptyString(): void
    {
        $result = Ansi::wrap('', '');
        $this->assertSame('', $result);
    }

    public function testWrapWithCodesWrapsTextInAnsiSequence(): void
    {
        $result = Ansi::wrap('hello', '1');
        $this->assertSame("\x1b[1mhello\x1b[0m", $result);
    }

    public function testWrapWithMultipleCodesWrapsCorrectly(): void
    {
        // "1;31" = bold + red
        $result = Ansi::wrap('error', '1;31');
        $this->assertSame("\x1b[1;31merror\x1b[0m", $result);
    }

    public function testWrapPreservesTextContent(): void
    {
        $text = 'Hello World 123!';
        $result = Ansi::wrap($text, '32');
        $this->assertStringContainsString($text, $result);
        $this->assertSame("\x1b[32m{$text}\x1b[0m", $result);
    }

    public function testWrapWithSpecialCharacters(): void
    {
        $text = "line1\nline2\twith\ttabs";
        $result = Ansi::wrap($text, '0');
        $this->assertSame("\x1b[0m{$text}\x1b[0m", $result);
    }

    public function testWrapReturnsAnsiEncodedString(): void
    {
        $result = Ansi::wrap('test', '1');
        // Verify the result starts with ESC [ and ends with m...ESC [0m
        $this->assertStringStartsWith("\x1b[", $result);
        $this->assertStringEndsWith("\x1b[0m", $result);
    }

    // =========================================================================
    // NO_COLOR (https://no-color.org)
    // =========================================================================

    public function testNoColorDisablesDecoration(): void
    {
        putenv('NO_COLOR=1');
        $this->assertSame('plain', Ansi::wrap('plain', '1;31'));
    }

    public function testEmptyNoColorDoesNotDisableDecoration(): void
    {
        // The standard: any NON-EMPTY value disables color; an empty one does not.
        putenv('NO_COLOR=');
        $this->assertSame("\x1b[1mtext\x1b[0m", Ansi::wrap('text', '1'));
    }

    public function testNoColorLeavesEmptyCodePathUnchanged(): void
    {
        putenv('NO_COLOR=1');
        $this->assertSame('plain', Ansi::wrap('plain', ''));
    }

    // =========================================================================
    // sanitize
    // =========================================================================

    public function testSanitizeStripsControlBytesButKeepsTabAndNewline(): void
    {
        $dirty = "keep\x00\x01\x07\x1b[31mreset\x09tail\x0aend";
        $clean = Ansi::sanitize($dirty);
        $this->assertStringNotContainsString("\x1b", $clean);
        $this->assertStringNotContainsString("\x00", $clean);
        $this->assertStringNotContainsString("\x07", $clean);
        $this->assertStringContainsString("keep", $clean);
        $this->assertStringContainsString("\x09", $clean); // tab preserved
        $this->assertStringContainsString("\x0a", $clean); // newline preserved
    }

    public function testSanitizeStripsCarriageReturn(): void
    {
        // CR would rewind the renderer's own line — it must never survive.
        $this->assertSame('ab', Ansi::sanitize("a\rb"));
        $this->assertSame('x', Ansi::sanitize("\x0dx"));
    }

    public function testSanitizeStripsDeleteAndHigherControls(): void
    {
        $this->assertSame('abx', Ansi::sanitize("a\x7fb\x1fx"));
    }

    public function testSanitizeKeepsMultibyteText(): void
    {
        $this->assertSame('héllo ✓', Ansi::sanitize('héllo ✓'));
    }
}

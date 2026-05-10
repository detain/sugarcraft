<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Ansi;

final class AnsiTest extends TestCase
{
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
}

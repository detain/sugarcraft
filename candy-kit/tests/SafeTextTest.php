<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Internal\SafeText;
use PHPUnit\Framework\TestCase;

/**
 * @see SafeText
 */
final class SafeTextTest extends TestCase
{
    public function testCleanAsciiPassesThroughUnchanged(): void
    {
        $input = 'Hello, World! 123';
        $this->assertSame($input, SafeText::line($input));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', SafeText::line(''));
    }

    public function testC0ControlBytesAreStripped(): void
    {
        // All C0 control bytes 0x00-0x1f should be stripped
        $input = "a\x00b\x01c\x02d\x03e\x04f\x05g\x06h\x07i"
               . "\x08j\x09k\x0al\x0bm\x0cn\x0do\x0ep\x0fq"
               . "\x10r\x11s\x12t\x13u\x14v\x15w\x16x\x17y"
               . "\x18z\x19\x1a\x1b\x1c\x1d\x1e\x1f!";
        $this->assertSame('abcdefghijklmnopqrstuvwxyz!', SafeText::line($input));
    }

    public function testDelByteIsStripped(): void
    {
        // DEL (0x7f) is stripped from the middle of text
        $this->assertSame('helloworld', SafeText::line("hello\x7fworld"));
    }

    public function testAnsiEscapeSequenceIsStripped(): void
    {
        // CSI sequence: ESC [ 2 J (screen clear)
        $input = "start\x1b[2Jafter";
        $this->assertSame('startafter', SafeText::line($input));
    }

    public function testAnsiOscSequenceIsStripped(): void
    {
        // OSC 52 clipboard sequence
        $input = "text\x1b]0;title\x07more";
        $this->assertSame('textmore', SafeText::line($input));
    }

    public function testAnsiSgrSequenceIsStripped(): void
    {
        // SGR color sequence - color codes are stripped, text preserved
        $input = "\x1b[38;2;255;0;0mred\x1b[0m normal";
        $this->assertSame('red normal', SafeText::line($input));
    }

    public function testMixedControlBytesAndAnsiAreStripped(): void
    {
        // Combination of control byte + ANSI sequence
        $input = "run\x1b[2Jx\x1b]0;t\x07end";
        $this->assertSame('runxend', SafeText::line($input));
    }

    public function testMultibyteUtf8IsPreserved(): void
    {
        // UTF-8 multibyte chars (all >= 0x80) must NOT be altered
        $input = '日本語 中文 한국어 Ελληνικά';
        $this->assertSame($input, SafeText::line($input));
    }

    public function testEmojiAndWideCharsArePreserved(): void
    {
        // Emoji (4-byte UTF-8) and wide characters
        $input = '🚀 日本語 🎉';
        $this->assertSame($input, SafeText::line($input));
    }

    public function testAnsiWithMultibyteUtf8StripsOnlyEscapeSequences(): void
    {
        // ANSI sequences interleaved with CJK text - UTF-8 chars must be preserved
        $input = "\x1b[38;2;255;0;0m日本語\x1b[0m";
        $this->assertSame('日本語', SafeText::line($input));
    }

    public function testBelAndOtherCommonInjectionBytesAreStripped(): void
    {
        // BEL (0x07) and other injection-worthy bytes
        $this->assertSame('test', SafeText::line("t\x07e\x1bs\x07t")); // ESC + BEL
    }

    public function testTabIsStripped(): void
    {
        // Tab (0x09) is part of C0 and is stripped entirely
        $this->assertSame('a b', SafeText::line("a\t b")); // tab stripped, spaces kept
    }

    public function testNewlineIsStripped(): void
    {
        // LF (0x0a) is in C0 range and is stripped entirely (no space substitution)
        $this->assertSame('helloworld', SafeText::line("hello\x0aworld"));
    }

    public function testCarriageReturnIsStripped(): void
    {
        // CR (0x0d) is in C0 range and is stripped entirely (no space substitution)
        $this->assertSame('helloworld', SafeText::line("hello\x0dworld"));
    }

    public function testOnlyAnsiSequencesReturnsEmpty(): void
    {
        // String with only ANSI sequences should return empty after stripping
        $input = "\x1b[38;2;255;0;0m\x1b[0m\x1b[2J";
        $this->assertSame('', SafeText::line($input));
    }

    public function testOnlyControlBytesReturnsEmpty(): void
    {
        $input = "\x00\x01\x02\x1f\x7f";
        $this->assertSame('', SafeText::line($input));
    }

    public function testAnsiResetSequenceIsStripped(): void
    {
        // ESC [ 0 m is SGR reset
        $input = "\x1b[1;32mgreen\x1b[0m normal";
        $this->assertSame('green normal', SafeText::line($input));
    }

    public function testComplexAnsiSequencePreservesVisibleText(): void
    {
        // Multiple ANSI sequences mixed with visible text
        // The ANSI sequences are stripped, the spaces around them are preserved
        $input = "\x1b[1m bold \x1b[0m and \x1b[38;5;196m red \x1b[0m text";
        $this->assertSame(' bold  and  red  text', SafeText::line($input));
    }
}

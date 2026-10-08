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

    /**
     * A PCRE failure must throw, never collapse to the empty string.
     *
     * The `preg_replace` here cannot actually fail: the pattern is a fixed
     * character class with no quantifier to backtrack, so neither
     * pcre.backtrack_limit nor pcre.recursion_limit is reachable by any input,
     * and the pattern compiles unconditionally. That makes the null arm
     * untriggerable from test space — lowering the limits in a child process
     * still returns a string (measured: n up to 1e5 chars against
     * backtrack_limit=1). So the contract is pinned on the source itself: if
     * the guard is deleted, or the old `?? ''` swallow is reinstated, the next
     * failure mode that DOES arrive (a rewritten pattern, PCRE terminated
     * mid-run) is silent again, and a frame renderer paints a stripped-to-nothing
     * label as a legitimately blank row.
     */
    public function testPcreFailureThrowsInsteadOfSwallowingEmpty(): void
    {
        $body = self::methodSource(SafeText::class, 'line');

        $this->assertStringNotContainsString(
            "?? ''",
            $body,
            'a null preg_replace result must not be coerced to an empty string',
        );
        $this->assertStringContainsString('=== null', $body, 'the failure arm must be checked explicitly');
        $this->assertStringContainsString('throw new \\RuntimeException', $body);
        $this->assertStringContainsString('preg_last_error_msg()', $body, 'the throw must name the PCRE cause');
    }

    /**
     * Discriminates the pin above: the clean-text path still works, so the
     * assertions are not merely matching a method that no longer strips.
     */
    public function testStripStillFunctionsAfterTheGuardWasAdded(): void
    {
        $this->assertSame('ab', SafeText::line("a\x1b[2J\x00b"));
    }

    /**
     * E453: the page variant keeps LF rows — the layout of an authored
     * multi-line page — while stripping everything {@see SafeText::line()}
     * strips. Tab and CR are NOT exempt: tabs render at terminal-dependent
     * widths, and dropping CR normalizes CRLF input to bare LF rows.
     */
    public function testPageKeepsLineFeedsAndStripsEveryOtherControl(): void
    {
        $input = "a\x1b[2Jb\nc\td\x07e\r\nf\x7fg";

        $this->assertSame("ab\ncde\nfg", SafeText::page($input));
    }

    public function testPageLeavesCleanMultiLineTextIdentical(): void
    {
        $page = "USAGE\n  myapp [flags]\n\nFLAGS\n  -v  verbose — café\n";

        $this->assertSame($page, SafeText::page($page));
    }

    /**
     * Same fail-loud source contract as {@see line()} — pinned on the body
     * because the null arm is untriggerable by construction (see the
     * reasoning above {@see testPcreFailureThrowsInsteadOfSwallowingEmpty}).
     */
    public function testPagePcreFailureThrowsInsteadOfSwallowingEmpty(): void
    {
        $body = self::methodSource(SafeText::class, 'page');

        $this->assertStringNotContainsString("?? ''", $body);
        $this->assertStringContainsString('=== null', $body);
        $this->assertStringContainsString('throw new \\RuntimeException', $body);
        $this->assertStringContainsString('preg_last_error_msg()', $body);
    }

    /**
     * @return string the declared body of $class::$method, for source-level pins
     *                of branches that are unreachable by construction
     */
    private static function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file       = $reflection->getFileName();
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();

        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', \array_slice($lines, $start, $end - $start - 1));
    }
}

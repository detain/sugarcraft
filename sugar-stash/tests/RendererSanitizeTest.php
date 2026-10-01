<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Stash\App;
use SugarCraft\Stash\Renderer;

/**
 * Pins for audit #7: sanitize() used a `/u` preg pattern, so any malformed
 * UTF-8 byte made preg_match return FALSE (PREG_BAD_UTF8_ERROR) and the whole
 * strip was skipped — ESC sequences rode straight through to the terminal.
 * The replacement rides candy-core's byte-wise fail-closed Sanitize::untrusted.
 */
final class RendererSanitizeTest extends TestCase
{
    private static function sanitize(string $s): string
    {
        $m = new ReflectionMethod(Renderer::class, 'sanitize');

        return $m->invoke(null, $s);
    }

    public function testBareEscapeIsStripped(): void
    {
        $out = self::sanitize("plain \x1b mid");

        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertSame('plain  mid', $out);
    }

    public function testWholeEscapeSequenceIsStripped(): void
    {
        // untrusted removes full CSI sequences, not just the lead byte —
        // no orphan "[31m" litter is left visible.
        $this->assertSame('ab', self::sanitize("a\x1b[31mb"));
    }

    public function testMalformedUtf8WithEmbeddedEscapeFailsClosed(): void
    {
        // \xe9 is a lone UTF-8 continuation byte: the old /u pattern aborted
        // on it and passed everything through. OSC + device-control payloads
        // hidden behind invalid bytes must still be neutralised.
        $hostile = "caf\xe9 \x1b]0;pwned\x07 tail \x1b[5Q";
        $out     = self::sanitize($hostile);

        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString('5Q', $out);
    }

    public function testBellIsStripped(): void
    {
        $this->assertSame('xy', self::sanitize("x\x07y"));
    }

    public function testNonStringC1ControlIsStripped(): void
    {
        // 0x92 (PU2) is a standalone C1 with no payload semantics.
        $this->assertSame('ab', self::sanitize("a\x92b"));
    }

    public function testStringIntroducerC1SwallowsItsPayload(): void
    {
        // 0x9f (APC) opens a string-terminated sequence: untrusted consumes
        // the payload too, so nothing hidden can ride through to the terminal.
        $this->assertSame('a', self::sanitize("a\x9fb"));
    }

    public function testValidUtf8IsPreservedVerbatim(): void
    {
        $text = 'garde ' . "\xc3\xa9" . 'crasé — 完整性 ✓';

        $this->assertSame($text, self::sanitize($text));
    }

    public function testTabNewlineCarriageReturnSurvive(): void
    {
        $this->assertSame("a\tb\nc\rd", self::sanitize("a\tb\nc\rd"));
    }

    /** End-to-end: a hostile log subject must not reach the frame un-scanned. */
    public function testHostileLogSubjectCannotInjectIntoFrame(): void
    {
        $g = new FixtureGit([], [], [
            ['sha' => 'abc1', 'subject' => "\x1b[1;1Hposessed\x07", 'author' => 'Joe', 'ago' => '5m ago'],
        ]);
        $a   = App::start($g);
        $out = Renderer::render($a);

        // The renderer emits its own SGR freely; what must be absent is the
        // injected cursor-home sequence and the bell.
        $this->assertStringNotContainsString("\x1b[1;1H", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringContainsString('posessed', $out);
    }
}

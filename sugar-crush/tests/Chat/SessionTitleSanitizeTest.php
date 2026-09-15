<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;

/**
 * Adversarial regression tests for {@see Chat::sanitizeSessionTitle()}.
 *
 * A session title is untrusted model output that lands verbatim in the tab
 * strip. Before this method was routed through the canonical C1-aware
 * sanitizer its hand-rolled regexes matched only `\x1b`-led OSC/CSI plus the C0
 * block, so the whole 8-bit C1 escape family (`\x9b` CSI, `\x9d` OSC, `\x90`
 * DCS, `\x9f` APC …) and any unterminated string-sequence payload walked
 * straight through the guard — a raw `\x9b` drives a cursor-move / erase /
 * title-set / sixel without ever emitting `\x1b[`. Each test below asserts two
 * things at once: the visible text survives, and NO control byte that a real
 * terminal would execute survives (see docs/research/ansi-tmux-ansicode-audit.md
 * #9 for the escape-family taxonomy).
 */
final class SessionTitleSanitizeTest extends TestCase
{
    /**
     * Invoke the private static sanitizer through reflection — the same seam
     * ChatTest uses for other private helpers (executionFailure, applyRewrite).
     */
    private static function sanitizeTitle(string $raw): string
    {
        $method = new \ReflectionMethod(Chat::class, 'sanitizeSessionTitle');
        $method->setAccessible(true);

        /** @var string $out */
        $out = $method->invoke(null, $raw);

        return $out;
    }

    /**
     * Fail-closed invariant shared by every case: the result must contain no
     * C0 control (other than the ones the line split already consumed), no
     * DEL, and no 8-bit C1 byte. Any survivor would re-arm the terminal.
     */
    private function assertNoExecutableControl(string $out, string $case): void
    {
        $this->assertSame(
            0,
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\x80-\x9f]/', $out),
            "sanitizeSessionTitle('$case') leaked an executable control byte: " . bin2hex($out)
        );
        $this->assertStringNotContainsString("\x1b", $out, "'$case' left a raw ESC");
        $this->assertStringNotContainsString("\x9b", $out, "'$case' left an 8-bit CSI introducer");
        $this->assertStringNotContainsString("\x9d", $out, "'$case' left an 8-bit OSC introducer");
        $this->assertStringNotContainsString("\x90", $out, "'$case' left an 8-bit DCS introducer");
    }

    public function testEightBitCsiCursorMoveIsStripped(): void
    {
        // `\x9b` is the 8-bit CSI introducer: `\x9bH` is CUP-to-home, `\x9b2J`
        // is erase display — the exact cursor/erase smuggling the `\x1b`-only
        // guard missed. The visible word must survive; the escape must not.
        $out = self::sanitizeTitle("Report\x9bH\x9b2Jdone");

        self::assertStringContainsString('Report', $out);
        self::assertStringContainsString('done', $out);
        self::assertStringNotContainsString('H', $out);
        $this->assertNoExecutableControl($out, '8-bit CSI cursor-move');
    }

    public function testEightBitTruecolorCsiIsStripped(): void
    {
        // A `\x9b`-led SGR truecolor sequence: parameters + `m` final consumed
        // whole, only the label text left.
        $out = self::sanitizeTitle("\x9b38;2;255;0;0mTitle");

        self::assertSame('Title', $out);
        $this->assertNoExecutableControl($out, '8-bit truecolor');
    }

    public function testEightBitOscTitleSetIsStripped(): void
    {
        // `\x9d` is the 8-bit OSC introducer — a title-set (`\x9d0;pwn BEL`)
        // smuggled without a single `\x1b`.
        $out = self::sanitizeTitle("A\x9d0;pwn\x07B");

        self::assertSame('AB', $out);
        self::assertStringNotContainsString('pwn', $out);
        $this->assertNoExecutableControl($out, '8-bit OSC title-set');
    }

    public function testNestedIntroducerInsideEightBitCsiIsStripped(): void
    {
        // A nested introducer: a 7-bit OSC-in-BEL wrapped inside an 8-bit CSI
        // lead. The old regexes handled `\x1b]…` and `\x1b[…` in isolation and
        // left the surrounding `\x9b` intact; nothing may survive to re-sync a
        // terminal parser onto attacker-chosen state.
        $out = self::sanitizeTitle("A\x9b\x1b]0;evil\x07B");

        self::assertSame('AB', $out);
        self::assertStringNotContainsString('evil', $out);
        $this->assertNoExecutableControl($out, 'nested introducer');
    }

    public function testTruncatedDcsPayloadDoesNotLeak(): void
    {
        // An unterminated DCS (`\x1bPtmux;echo …` with no ST/BEL): the whole
        // payload must be discarded fail-closed, never released as a "safe"
        // remainder that a resynchronising terminal executes on the next write.
        $out = self::sanitizeTitle("Status \x1bPtmux;echo pwned");

        self::assertStringContainsString('Status', $out);
        self::assertStringNotContainsString('tmux', $out);
        self::assertStringNotContainsString('pwned', $out);
        $this->assertNoExecutableControl($out, 'truncated DCS');

        // The 8-bit DCS introducer (`\x90`) is the exact one the old `\x1b`-only
        // regex never matched; the unescaped payload must be discarded just as
        // fail-closed.
        $out8 = self::sanitizeTitle("Status \x90tmux;echo pwned");

        self::assertStringContainsString('Status', $out8);
        self::assertStringNotContainsString('tmux', $out8);
        self::assertStringNotContainsString('pwned', $out8);
        $this->assertNoExecutableControl($out8, 'truncated 8-bit DCS');
    }

    public function testTruncatedApcPayloadDoesNotLeak(): void
    {
        // An unterminated APC (Kitty graphics lead `\x1b_`): same fail-closed
        // contract as DCS.
        $out = self::sanitizeTitle("Label \x1b_Gi;sixelpayload");

        self::assertStringContainsString('Label', $out);
        self::assertStringNotContainsString('sixel', $out);
        $this->assertNoExecutableControl($out, 'truncated APC');

        // 8-bit APC introducer (`\x9f`) — the Kitty/sixel family smuggled
        // without a single `\x1b`.
        $out8 = self::sanitizeTitle("Label \x9fGi;sixelpayload");

        self::assertStringContainsString('Label', $out8);
        self::assertStringNotContainsString('sixel', $out8);
        $this->assertNoExecutableControl($out8, 'truncated 8-bit APC');
    }

    public function testBenignTitleSurvivesUnchanged(): void
    {
        // No over-stripping: an ordinary visible string round-trips untouched.
        $out = self::sanitizeTitle('Session: fix the login redirect bug');

        self::assertSame('Session: fix the login redirect bug', $out);

        // Multi-byte UTF-8 (東京) is legitimate model output and MUST survive
        // whole. `Ansi::strip()` treats a 0x80-0x9F byte as a lone C1 control
        // only when it does not continue a well-formed sequence, so the 0x9D
        // inside 東 (e6 9d b1) is kept. Asserted at byte level because the
        // class-wide `assertNoExecutableControl` check intentionally flags any
        // bare 0x80-0x9F — including a valid CJK continuation byte — so it
        // cannot be reused here; we forbid only ESC, stray C0 and DEL.
        $cjk = self::sanitizeTitle('Fix 東京 login');

        self::assertSame('Fix 東京 login', $cjk);
        self::assertSame(
            0,
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\x1b/', $cjk),
            'CJK title leaked an ASCII control byte: ' . bin2hex($cjk)
        );
    }

    public function testSevenBitSgrIsStrippedAndTextKept(): void
    {
        // Regression-lock for the previous behaviour: a 7-bit SGR colour is
        // removed (a title strip paints no colour) while the text survives.
        $out = self::sanitizeTitle("hidden\x1b[31mReal Title\x1b[0m");

        self::assertStringNotContainsString("\x1b", $out);
        self::assertStringContainsString('Real Title', $out);
        $this->assertNoExecutableControl($out, '7-bit SGR');
    }

    public function testFirstPrintableLineSemanticsPreserved(): void
    {
        // The single-line-per-tab contract: an escape that would otherwise
        // swallow the newline split must not change which line is chosen, and
        // a smuggled `\x9b`-led sequence spanning what looks like a break is
        // neutralised before the split.
        $out = self::sanitizeTitle("Real Title\x9b1;1H\nsecond line ignored");

        self::assertSame('Real Title', $out);
        self::assertStringNotContainsString('second line', $out);
    }
}

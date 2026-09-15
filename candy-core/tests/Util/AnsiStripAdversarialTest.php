<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;

/**
 * Adversarial coverage for {@see Ansi::strip()} and
 * {@see Sanitize::untrusted()} — the ANSI audit (docs/research/
 * ansi-tmux-ansicode-audit.md, defect #9) found the old hand-rolled
 * stripper blind to DCS/SOS/PM/APC string payloads and the 8-bit C1
 * introducers, so untrusted bytes could smuggle control sequences
 * through a sanitizer.
 *
 * Every case here is a bypass class the fix must close: it must strip
 * ALL escape sequences (CSI/OSC/DCS/SOS/PM/APC, BEL- and ST-terminated,
 * 7-bit and 8-bit), be idempotent, and never split a partial sequence
 * into a false "safe" remainder that a re-synchronising terminal re-arms.
 *
 * Mirrors ECMA-48 §15.9-15.12 (CSI/DCS/SOS/PM/APC) and §8.3.9-8.3.13.
 */
final class AnsiStripAdversarialTest extends TestCase
{
    private const ST = "\x1b\\";

    // ---- string-sequence payloads (DCS/SOS/PM/APC) ------------------------

    public function testStripsDcsSixelPayload(): void
    {
        // The exact audit evidence: a DECSIXEL body must not leak its params.
        $this->assertSame(
            'AB',
            Ansi::strip("A\x1bP0;1;0q\"1;1;2;2~abc" . self::ST . 'B'),
        );
    }

    public function testStripsApcKittyPayload(): void
    {
        $this->assertSame('AB', Ansi::strip("A\x1b_Ga=T;i=1;AAAA" . self::ST . 'B'));
    }

    public function testStripsPmAndSosPayloads(): void
    {
        $this->assertSame('AB', Ansi::strip("A\x1b^privacy text" . self::ST . 'B'));
        $this->assertSame('AB', Ansi::strip("A\x1bXout msg" . self::ST . 'B'));
    }

    public function testStripsTmuxPassthroughDcsBody(): void
    {
        // untrusted() promised "safe for terminal output" but passed a tmux
        // DCS passthrough body straight through: the whole `tmux;echo` ran.
        $this->assertSame('AB', Ansi::strip('A' . "\x1bPtmux;echo" . self::ST . 'B'));
        $this->assertSame('AB', Sanitize::untrusted('A' . "\x1bPtmux;echo" . self::ST . 'B'));
    }

    public function testStringSequenceTerminatedBy8BitSt(): void
    {
        // 0x9C is the 8-bit ST — a DCS closed only by 0x9C must still strip.
        $this->assertSame('AB', Ansi::strip("A\x1b_PASSTHRU\x9cB"));
    }

    // ---- nested / interrupted introducers ---------------------------------

    public function testEscInterruptedCsiIsReReadAsFreshDcs(): void
    {
        // `ESC [ 31 ESC P ...` — the inner ESC cancels the half-built CSI and
        // must be re-read as a DCS introducer, swallowing the sixel body.
        // The naive single-scan consumed to the first 0x40-0x7e (the `q`),
        // leaving `"1;1;2;2~` behind as false-safe text.
        $hostile = "A\x1b[31\x1bPq\"1;1;2;2~" . self::ST . 'B';
        $this->assertSame('AB', Ansi::strip($hostile));
    }

    public function testEscInsideStringCancelsAndRescans(): void
    {
        // DCS payload containing a bare ESC (not an ST): ECMA-48 has ESC
        // cancel the string, so the following `]0;evil` OSC must ALSO be
        // stripped, not released as literal text.
        $hostile = 'A' . "\x1bPq" . "\x1b]0;evil\x07" . 'B';
        $this->assertSame('AB', Ansi::strip($hostile));
    }

    public function testTruncatedDcsWithNoTerminatorDiscardsRest(): void
    {
        // Unterminated DCS at end-of-input: fail-closed, discard the payload.
        $this->assertSame('AB', Ansi::strip("AB\x1bP0;1q\"2;2~trailing"));
        $this->assertSame('', Ansi::strip("\x1b_PAYLOAD_NO_ST"));
    }

    public function testTruncatedOscWithNoTerminatorDiscardsRest(): void
    {
        // Unterminated OSC eats the rest of the input — `gone` is payload,
        // never a "safe" remainder.
        $this->assertSame('title', Ansi::strip('title' . "\x1b]0;" . 'gone'));
    }

    // ---- OSC terminator variants ------------------------------------------

    public function testOscBelVsStBothTerminate(): void
    {
        $this->assertSame('after', Ansi::strip("\x1b]0;title\x07after"));
        $this->assertSame('after', Ansi::strip("\x1b]0;title" . self::ST . 'after'));
    }

    public function testOscBelDoesNotTerminateDcs(): void
    {
        // BEL is an xterm OSC extension only. Inside a DCS, a BEL is just
        // payload — the sequence must run to the real ST, not stop at BEL.
        // A DCS body `...\x07evil<ST>` must drop everything through ST.
        $hostile = 'A' . "\x1bPq\x07evil" . self::ST . 'B';
        $this->assertSame('AB', Ansi::strip($hostile));
    }

    // ---- 8-bit C1 introducers ---------------------------------------------

    public function testCsi8BitForm(): void
    {
        // 0x9B is the 8-bit CSI. Audit evidence: `a\x9b31mb` was byte-identical.
        $this->assertSame('ab', Ansi::strip("a\x9b31m" . 'b'));
        $this->assertNotSame("a\x9b31mb", Ansi::strip("a\x9b31mb"));
    }

    public function testString8BitForms(): void
    {
        $this->assertSame('ab', Ansi::strip("a\x90qdata\x9cb"));   // 8-bit DCS + 8-bit ST
        $this->assertSame('ab', Ansi::strip("a\x9d0;title\x9cb"));  // 8-bit OSC
        $this->assertSame('ab', Ansi::strip("a\x9fGkitty\x9cb"));   // 8-bit APC
        $this->assertSame('ab', Ansi::strip("a\x9eprivacy\x9cb"));  // 8-bit PM
        $this->assertSame('ab', Ansi::strip("a\x98oldmsg\x9cb"));   // 8-bit SOS
    }

    /**
     * The companion bypass from the audit: a lone 0x9b made the old
     * `/u` preg in untrusted() fail, and the `?? $stripped` fallback then
     * let a subsequent BEL (0x07) survive. It must not.
     */
    public function testLoneC1PlusBelDoesNotLeakControl(): void
    {
        $out = Sanitize::untrusted("a\x9b\x07b");
        $this->assertStringNotContainsString("\x07", $out, 'BEL must never survive untrusted()');
    }

    // ---- interleaved ST ----------------------------------------------------

    public function testInterleavedStConsumedAsTwoByteEscape(): void
    {
        // A bare `ESC \` (ST) with no open string is a two-byte Fe escape;
        // both bytes go, surrounding text stays.
        $this->assertSame('ab', Ansi::strip('a' . self::ST . 'b'));
    }

    // ---- UTF-8 preservation ------------------------------------------------

    public function testPreservesMultibyteUtf8WithContinuationBytesInC1Range(): void
    {
        // These multibyte sequences have trailing bytes in 0x80-0x9f that are
        // UTF-8 continuations, NOT lone C1 controls. Width math depends on
        // keeping them intact.
        $arrow = "\xe2\x86\x92";       // →  (0x92 tail)
        $check = "\xe2\x9c\x93";       // ✓  (0x93 tail)
        $c1cp  = "\xc2\x80";           // U+0080 (0x80 tail)
        $emoji = "\xf0\x9f\x98\x80";   // 😀  (0x9f interior)
        $s = $arrow . $check . $c1cp . $emoji;
        $this->assertSame($s, Ansi::strip($s), 'valid UTF-8 must survive verbatim');
    }

    public function testStrayEscFollowedByMultibyteKeepsTheCharacter(): void
    {
        // A lone ESC followed by a lead byte is treated as a stray ESC; only
        // the ESC is dropped so the multibyte character after it survives.
        $this->assertSame("\xe2\x86\x92", Ansi::strip("\x1b\xe2\x86\x92"));
    }

    // ---- idempotency -------------------------------------------------------

    public function testIdempotentAcrossAdversarialCorpus(): void
    {
        $corpus = [
            "A\x1bP0;1q\"1;1~x" . self::ST . 'B',
            "a\x9b31m" . 'b',
            'A' . "\x1bPtmux;echo" . self::ST . 'B',
            "\x1b[31\x1bPq" . self::ST,
            "a\x1b\x07b",
            "\xe2\x86\x92\x1b]0;t\x07",
            'plain text untouched',
            '',
        ];
        foreach ($corpus as $s) {
            $once = Ansi::strip($s);
            $this->assertSame($once, Ansi::strip($once), 'strip() must be idempotent for: ' . bin2hex($s));
            $u = Sanitize::untrusted($s);
            $this->assertSame($u, Sanitize::untrusted($u), 'untrusted() must be idempotent for: ' . bin2hex($s));
        }
    }

    public function testIdempotentUnderRandomByteFuzz(): void
    {
        // Deterministic PRNG-seeded fuzz over a control-heavy alphabet:
        // stripping twice must equal stripping once (the core safety property
        // that stops a partial release being re-armed on the second pass).
        mt_srand(0xC0FFEE);
        $alphabet = [
            "\x1b", "\x1b[", "\x1b]", "\x1bP", "\x1b_", "\x1b^", "\x1bX",
            self::ST, "\x07", "\x9b", "\x9d", "\x90", "\x9f", "\x9c", "\x9e",
            'a', '1', ';', 'm', 'q', '~', '"', "\xe2\x86\x92",
        ];
        for ($trial = 0; $trial < 400; $trial++) {
            $s = '';
            for ($k = 0; $k < 12; $k++) {
                $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $once = Ansi::strip($s);
            $this->assertSame(
                $once,
                Ansi::strip($once),
                'strip() not idempotent for input bytes: ' . strtoupper(bin2hex($s)),
            );
        }
    }

    // ---- sixel / kitty cannot survive (the proof the fix closes the hole) --

    public function testSixelAndKittyPayloadsCannotSurviveSanitizer(): void
    {
        // A hostile renderer emits a full sixel frame and a full Kitty APC
        // frame; after untrusted() NOTHING that could re-arm the terminal
        // remains — no ESC (0x1b), no lone C1, and the base64/param bodies
        // are gone.
        $six = "\x1bP0;1;0q\"1;1;4;4#0;1;1;1;1#1;2;3;3\x1b\\";
        $kit = "\x1b_Ga=T,f=100,m=1;AAECgkI=" . self::ST;

        $out = Sanitize::untrusted('X' . $six . $kit . 'Y');

        $this->assertStringNotContainsString("\x1b", $out, 'no ESC may survive');
        $this->assertSame('', preg_replace('/[\x20-\x7e\x09\x0a\x0d\xc2-\xf4]/', '', $out), 'no raw control byte may survive');
        // The visible frame letters survive; the injected bodies do not.
        $this->assertSame('XY', Ansi::strip('X' . $six . $kit . 'Y'));
    }
}

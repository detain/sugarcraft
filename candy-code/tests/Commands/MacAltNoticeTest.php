<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * TUI-5 stage 1 (plan_comparison 6.15): the macOS Option-as-Meta launch hint.
 *
 * The gate is PURE — (os family, kitty-capable) in, notice-or-null out — so
 * every quadrant is pinned on this Linux host even though only a Mac will
 * ever see the row. The `kittyCapable` half stands in for real negotiation
 * state honestly: `App::init()` PUSHES `pushKittyKeyboard()` fire-and-forget
 * and the reply family has no consumer, so the app cannot know whether the
 * terminal understood. The probe is therefore env-shaped (TERM/TERM_PROGRAM
 * naming a kitty-speaking terminal), and the notice wording says "may be"
 * for exactly that reason — see the method's doc-block.
 */
final class MacAltNoticeTest extends TestCase
{
    public function testDarwinWithoutAKittySpeakingTerminalGetsTheRow(): void
    {
        $row = Bootstrap::macAltNotice('Darwin', false);

        self::assertNotNull($row);
        self::assertStringContainsString('Option', $row, 'the row must name the setting the operator flips');
        self::assertStringContainsString('may be', $row, 'the app cannot probe negotiation — the wording must stay honest about that');
        self::assertStringContainsString('/keys', $row, 'the escape hatch the hint points at is the in-app reference');
    }

    public function testEveryOtherQuadrantStaysSilent(): void
    {
        // A Mac on kitty/ghostty/wezterm asked for the keyboard, so an Alt
        // press either arrives or the user already owns the trade; and a
        // non-Mac never sees Option-as-Meta at all.
        self::assertNull(Bootstrap::macAltNotice('Darwin', true));
        self::assertNull(Bootstrap::macAltNotice('Linux', false));
        self::assertNull(Bootstrap::macAltNotice('Linux', true));
        self::assertNull(Bootstrap::macAltNotice('Windows', false));
        self::assertNull(Bootstrap::macAltNotice('BSD', false));
    }

    public function testTheProbeConsultsBothTerminalEnvironmentSpellings(): void
    {
        // TERM and TERM_PROGRAM are set by different emulators for different
        // reasons; the probe consults both. The body is pinned by source shape
        // because the ambient env of the runner is not ours to rewrite here —
        // the quadrant table above owns the behaviour.
        $source = (string) file_get_contents((new \ReflectionClass(Bootstrap::class))->getFileName());

        self::assertSame(
            1,
            preg_match('/getenv\([\'"]TERM[\'"]\).*getenv\([\'"]TERM_PROGRAM[\'"]\)/s', $source),
            'the kitty probe must consult TERM and TERM_PROGRAM',
        );
        foreach (['kitty', 'ghostty', 'wezterm'] as $terminal) {
            self::assertStringContainsString($terminal, $source, $terminal . ' names a keyboard-negotiating terminal and belongs in the probe');
        }
    }

    public function testTheRowIsRecordedNotWarned(): void
    {
        // The launch-cap census (BootstrapLaunchNoticeCapCensusTest) rosters
        // bounded warnLaunchRows() callers; this hint joins NEITHER channel:
        // it rides RuntimeNoticeSink::record() — inbox-only, so a headless
        // `-p` run never grows an unsolicited stderr row, and the TUI drains
        // it to the transcript like every other runtime notice.
        $body = self::bootstrapBodyOf('announceMacAltFallback');

        self::assertStringContainsString('RuntimeNoticeSink::record(', $body);
        self::assertStringNotContainsString('warn(', $body, 'record() is the chosen channel — warn() would smear the row onto stderr for every embedder');
        self::assertStringNotContainsString('warnLaunchRows', $body, 'a new launch-buffer caller would drift the cap census behind its roster');
    }

    /** @return string the trimmed body of the named Bootstrap method */
    private static function bootstrapBodyOf(string $method): string
    {
        $source = (string) file_get_contents((new \ReflectionClass(Bootstrap::class))->getFileName());


        // E276 doctrine, two halves. THE Bootstrap: the declaration is located
        // on the token stream — a docblock spelling `static function name(`
        // is not the declaration, and byte-anchoring there slices the file
        // mid-comment so the whole tail lexes as one T_COMMENT. THE WALK:
        // depth counts every token that OPENS a brace — block `{` (its own
        // single-char token), interpolated `{$` (T_CURLY_OPEN), deprecated
        // `${` (T_DOLLAR_OPEN_CURLY_BRACES) — never raw bytes, so a string
        // literal carrying an unmatched brace can neither end the body
        // early nor swallow its closer. The bare `}` needs no id arm.
        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);
        $nameAt = -1;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== \T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->id === \T_WHITESPACE || $tokens[$j]->text === '&') {
                    continue;
                }
                if ($tokens[$j]->id === \T_STRING && $tokens[$j]->text === $method) {
                    $nameAt = $j;
                }
                break;
            }
            if ($nameAt >= 0) {
                break;
            }
        }
        self::assertNotSame(-1, $nameAt, $method . ' must exist on Bootstrap');

        $depth = 0;
        $openPos = 0;
        for ($i = $nameAt + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->text === '{' && $token->id !== \T_CURLY_OPEN) {
                if ($depth === 0) {
                    $openPos = $token->pos;
                }
                $depth++;
            } elseif ($token->id === \T_CURLY_OPEN || $token->id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openPos, $token->pos - $openPos + 1);
                }
            }
        }

        self::fail('unbalanced body for ' . $method);
    }
}

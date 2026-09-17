<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * ECMA-48 colon forms of the extended-colour SGRs must resolve to exactly
 * what their semicolon spellings do. The candy-ansi parser flattens `:` into
 * ordinary slots and marks the continuation in {@see Parser::subparams()};
 * until {@see ScreenHandler} fed those flags to SgrHandler::extended(), a
 * six-slot truecolor group was misread positionally:
 *
 * - `38:2::255:0:0` (omitted colour space) flattened to [38,2,-1,255,0,0];
 *   the positional read took the empty slot as red, slid the triplet by one
 *   and left the trailing 0 to replay as an INDEPENDENT SGR 0 — a full pen
 *   reset, so `foreground` came back NULL and the emitted style vanished.
 * - `38:2:16:255:0:0` (explicit colour space id) failed identically: the CS
 *   byte was consumed as red and the leftover 0 reset the pen.
 *
 * The references agree on the fix — one group, and the direct-colour triplet
 * is the group's LAST three slots unless the group is exactly five wide:
 * - xterm `parse_extended_colors()`: sub-parameters are read with
 *   `get_subparam(base, 2 + n + (have > 4))` (charproc.c:2142-2146) — a
 *   group wider than five carries a leading colour-space selector; and the
 *   colon group is consumed whole via `next = item + have` (charproc.c:2144)
 *   whatever the parse outcome, so slots can never replay as separate SGRs.
 * - tmux `input_csi_dispatch_sgr_colon()`: kind 2 with `if (n == 5) i = 2;
 *   else i = 3;` then rgb at p[i..i+2] (input.c:2358-2369); the colour-space
 *   slot is skipped, never resolved.
 * tmux serves 38/48/58 through this one path (p[0] tested against all three
 * at input.c:2356), so 48: and 58: are in scope here, and 58: must
 * additionally leave the pen untouched rather than eat one slot too many or
 * too few; xterm-411 has no SGR-58 arm at all — parse_extended_colors is
 * called from its 38 and 48 arms only (charproc.c:4491,4529) — so the 58
 * rule above follows tmux.
 *
 * The colon group's own slots never leak: a malformed `38:2` (group too
 * short) consumes the group and sets nothing — the same fail-quiet shape
 * xterm reaches via its pre-switch `item = next` (charproc.c:2170).
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (SGR 38/48/58 colon syntax)
 */
final class ColonExtendedColourTest extends TestCase
{
    private function sgrAfter(string $csi): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer(12, 4));
        $parser = new Parser($h);
        // The handler is the parser's sink and a SubparamsAwareHandler: the
        // colon flags arrive by push, exactly as in the emulator facade
        // (Terminal\Terminal) — no provider wiring here or there.
        // A printable afterwards: the pen must survive into the cell that
        // uses it, and a stray SGR 0 would have reset it before then.
        $parser->feed($csi . 'X');
        return $h;
    }

    /**
     * The assertion with teeth: the colon spelling resolves to the SAME
     * Color the semicolon spelling does, while the semicolon baseline is
     * itself pinned to literal RGB so the pair cannot both drift.
     */
    private function assertColonMatchesSemicolon(string $semicolon, string $colon, string $property): void
    {
        $viaSemicolon = $this->sgrAfter($semicolon)->sgr->{$property};
        $viaColon = $this->sgrAfter($colon)->sgr->{$property};

        $this->assertInstanceOf(Color::class, $viaSemicolon, "semicolon baseline {$semicolon} must set {$property}");
        $this->assertInstanceOf(Color::class, $viaColon, "colon form {$colon} must set {$property} — it resolved to nothing");
        $this->assertTrue(
            $viaColon->equals($viaSemicolon),
            "{$colon} must equal {$semicolon} for {$property}"
        );
    }

    public function testTruecolorOmittedColourSpaceMatchesSemicolonForm(): void
    {
        $this->assertColonMatchesSemicolon("\x1b[38;2;255;0;0m", "\x1b[38:2::255:0:0m", 'foreground');
        $h = $this->sgrAfter("\x1b[38:2::255:0:0m");
        $this->assertSame([255, 0, 0], [
            $h->sgr->foreground->red(),
            $h->sgr->foreground->green(),
            $h->sgr->foreground->blue(),
        ], 'and specifically red');
    }

    public function testTruecolourExplicitColourSpaceMatchesSemicolonForm(): void
    {
        $this->assertColonMatchesSemicolon("\x1b[38;2;255;0;0m", "\x1b[38:2:16:255:0:0m", 'foreground');
    }

    public function testTruecolourFlatColonGroupMatchesSemicolonForm(): void
    {
        $this->assertColonMatchesSemicolon("\x1b[38;2;0;255;0m", "\x1b[38:2:0:255:0m", 'foreground');
    }

    public function testBackgroundColonFormsMatchSemicolonForm(): void
    {
        $this->assertColonMatchesSemicolon("\x1b[48;2;255;0;0m", "\x1b[48:2::255:0:0m", 'background');
        $this->assertColonMatchesSemicolon("\x1b[48;2;255;0;0m", "\x1b[48:2:16:255:0:0m", 'background');
        $this->assertColonMatchesSemicolon("\x1b[48;2;0;0;255m", "\x1b[48:2:0:0:255m", 'background');
    }

    public function testIndexedColonFormsMatchSemicolonForm(): void
    {
        $this->assertColonMatchesSemicolon("\x1b[38;5;99m", "\x1b[38:5:99m", 'foreground');
        $this->assertColonMatchesSemicolon("\x1b[48;5;196m", "\x1b[48:5:196m", 'background');
    }

    public function testUnderlineColourColonFormsLeavePenUntouched(): void
    {
        // 58 has nowhere to store a colour in this pen model — the point is
        // that the whole colon group is consumed: no slot replays as an
        // independent SGR (which is how the trailing 0 of a six-slot group
        // used to reset the pen), and the result equals the semicolon form.
        $viaSemicolon = $this->sgrAfter("\x1b[1;30;58;2;255;0;0m");
        foreach (['38' => "\x1b[1;30;58:2::255:0:0m", '16' => "\x1b[1;30;58:2:16:255:0:0m", 'flat' => "\x1b[1;30;58:2:255:0:0m", 'idx' => "\x1b[1;30;58:5:9m"] as $tag => $seq) {
            $viaColon = $this->sgrAfter($seq);
            $this->assertTrue($viaColon->sgr->bold, "58 colon group ({$tag}) must not replay a reset over bold");
            $this->assertNotNull($viaColon->sgr->foreground, "58 colon group ({$tag}) must not disturb the fg");
            $this->assertTrue(
                $viaColon->sgr->foreground->equals($viaSemicolon->sgr->foreground),
                "58 colon ({$tag}) must match the semicolon form's pen"
            );
        }
    }

    public function testColonGroupIsFollowedByIndependentSgr(): void
    {
        // `38:2::255:0:0;1m` — after the six-slot group ends, the semicolon
        // param is a real, independent SGR: bold must be ON and fg RED.
        $h = $this->sgrAfter("\x1b[38:2::255:0:0;1m");
        $this->assertTrue($h->sgr->bold, 'trailing ;1 after a colon group is an independent SGR');
        $this->assertNotNull($h->sgr->foreground);
        $this->assertSame([255, 0, 0], [
            $h->sgr->foreground->red(),
            $h->sgr->foreground->green(),
            $h->sgr->foreground->blue(),
        ], 'the colon colour survives the following independent SGR');
    }

    public function testMalformedShortColonGroupConsumesWithoutReplay(): void
    {
        // `38:2;1m`: the group is [38,2] — too short for a triplet. Its
        // slots must not replay (an SGR 2 would dim the pen); the following
        // independent `;1` still lands as bold.
        $h = $this->sgrAfter("\x1b[38:2;1m");
        $this->assertTrue($h->sgr->bold, 'the ;1 after a truncated colon group is independent');
        $this->assertFalse($h->sgr->dim, 'group slots must not replay — SGR 2 (dim) must not fire');
        $this->assertNull($h->sgr->foreground, 'no colour is invented for a truncated group');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Adapter bridging the Parser's {@see Handler} interface to
 * {@see CsiHandler} + {@see OscHandler}.
 *
 * Implements the {@see BulkPrintHandler} capability: the adapter's own
 * printRun() loops its per-char leg, so parser-level run collapsing never
 * changes what the CsiHandler side observes (one printable() per rune).
 *
 * Translates parse events into handler method calls. The adapter is a
 * DELIBERATE SIEVE: `CsiHandler` covers the renderer's working CSI set and
 * the OSC subset for titles/hyperlinks, so ESC sequences, DCS, SOS/PM/APC
 * strings, unmapped CSI finals (HPA `G`, VPA `d`, ECH `X`, DSR `n`, DECSCUSR
 * `SP q`, DA `c`, …) and most C0/C1 executes (BEL, VT, FF, IND, NEL, RI …)
 * stop here by design — `sugar-vterm` implements the wider set on its own
 * handler instead of through this adapter.
 *
 * Consumers that need the rest pass an optional `$fallback` handler:
 * escDispatch/dcsDispatch/sosPmApcDispatch are ALWAYS forwarded to it, and
 * execute/csiDispatch/oscDispatch forward the events the sieve did not
 * consume (the SCO-gated `CSI ? s/u` discard is considered consumed —
 * forwarding it would re-open the cursor-teleport defect gated below).
 *
 * Cite honesty (finding sugar-ansi-parser-004): lib-original. Upstream
 * charmbracelet/x/ansi routes parse events through handler FUNCTION FIELDS
 * and ships no `HandlerAdapter` class — the sieve + `$fallback` design above
 * is ours, not a mirror.
 */
final class HandlerAdapter implements BulkPrintHandler
{
    /**
     * Construction-site note for finding sugar-ansi-parser-007: the three-
     * argument shape is the safe one, but the `$fallback` stays optional for
     * BC — built WITHOUT it the sieve's drops are silent, which is exactly
     * the trap renderer bridges keep falling into.
     *
     * @see self::wired() for the shape that makes the fallback mandatory.
     */
    public function __construct(
        private readonly CsiHandler $csi,
        private readonly OscHandler $osc,
        private readonly ?Handler $fallback = null,
    ) {
    }

    /**
     * Named constructor with a MANDATORY fallback — the compile-time refusal
     * of the silent-drop shape (finding sugar-ansi-parser-007, arm a). The
     * bare three-arg `new` remains for BC, but code that must observe the
     * ESC family, DCS, SOS/PM/APC payloads, unmapped CSI finals and the
     * non-routed executes can now say so at the call site instead of
     * discovering the loss in a screenshot.
     */
    public static function wired(CsiHandler $csi, OscHandler $osc, Handler $fallback): self
    {
        return new self($csi, $osc, $fallback);
    }

    /**
     * A NEW adapter whose sieve-unconsumed events route to $fallback, sharing
     * this one's csi/osc sides (finding sugar-ansi-parser-007, arm b). The
     * swap constructs rather than mutates — the adapter is stateless — so
     * $this keeps its existing fallback (or silence) untouched.
     */
    public function withFallback(Handler $fallback): self
    {
        return new self($this->csi, $this->osc, $fallback);
    }

    public function printChar(string $rune): void
    {
        // Defense-in-depth against a hand-called adapter, not a live rule:
        // the parser never routes a sub-0x20-leading rune here (C0 arrives
        // via execute(); runes start at plain ASCII or a lead byte >= 0xC2).
        // C1 bytes 0x80-0x9F are never seen on this path at all — they
        // execute or introduce sequences upstream (Parser's UTF-8 policy).
        if ($rune !== '' && ord($rune[0]) >= 0x20) {
            $this->csi->printable($rune);
        }
    }

    public function printRun(string $run): void
    {
        // The {@see BulkPrintHandler} leg: byte-identical to per-char
        // printChar() over the same run, because the parser only ever
        // collects 0x20-0x7E bytes into a run and printChar()'s sieve guard
        // is vacuously true for those. A substr loop rather than str_split:
        // zero per-run array allocation, and the rune-per-call contract
        // toward the CsiHandler side is unchanged (one printable() per byte).
        $length = strlen($run);
        for ($k = 0; $k < $length; $k++) {
            $this->csi->printable($run[$k]);
        }
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x09 => $this->csi->cht(1), // HT — tab to next stop
            0x0A => $this->csi->lf(),   // LF — line feed
            0x0D => $this->csi->cr(),   // CR — carriage return
            0x08 => $this->csi->cub(1), // BS — backspace
            default => $this->fallback?->execute($byte),
        };
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $finalChar = chr($final);
        $p0 = (int) ($params[0] ?? -1);
        $p1 = (int) ($params[1] ?? -1);
        $count = $p0 === -1 ? 1 : max(1, $p0);

        // SCO save/restore-cursor are the UNMARKED `CSI s`/`CSI u` only. A
        // private marker routes the final byte to a different action in both
        // references: xterm's `?` switches to dec_table, where 's' saves the
        // DEC private MODE settings (VTPrsTbl.c:4195 CASE_XTERM_SAVE →
        // savemodes(), charproc.c:6209-6210,8049-8053) and 'u' is ignored
        // (VTPrsTbl.c:4198); tmux keys ('s',"")/('u',"") without markers only
        // (input.c:345,347) and lets `CSI ? u` miss the table entirely
        // (input.c:1483-1487). Executing the kitty keyboard-capability QUERY
        // (`CSI ? u`) as SCORC teleports the render cursor — the same defect
        // the emulator gates in SugarCraft\Vterm\Handler\ScreenHandler::csiDispatch().
        if ($prefix !== 0 && ($finalChar === 's' || $finalChar === 'u')) {
            return;
        }

        match ($finalChar) {
            'A' => $this->csi->cuu($count),
            'B' => $this->csi->cud($count),
            'C' => $this->csi->cuf($count),
            'D' => $this->csi->cub($count),
            'H' => $this->csi->cup(
                $p0 === -1 ? 1 : max(1, $p0),
                $p1 === -1 ? 1 : max(1, $p1),
            ),
            'f' => $this->csi->hvp(
                $p0 === -1 ? 1 : max(1, $p0),
                $p1 === -1 ? 1 : max(1, $p1),
            ),
            'm' => $this->csi->sgr($params),
            'J' => $this->csi->ed($p0 === -1 ? 0 : $p0),
            'K' => $this->csi->el($p0 === -1 ? 0 : $p0),
            'r' => $this->csi->decstbm(
                $p0 === -1 ? 1 : $p0,
                $p1 === -1 ? $this->csi->gridRows() : $p1,
            ),
            'h' => $this->dispatchModes($params, $prefix, set: true),
            'l' => $this->dispatchModes($params, $prefix, set: false),
            'g' => $this->csi->tbc($p0 === -1 ? 0 : $p0),
            'Z' => $this->csi->cbt($count),
            'I' => $this->csi->cht($count),
            'S' => $this->csi->su($count),
            'T' => $this->csi->sd($count),
            'L' => $this->csi->il($count),
            'M' => $this->csi->dl($count),
            '@' => $this->csi->ich($count),
            'P' => $this->csi->dch($count),
            'b' => $this->csi->rep($count),
            's' => $this->csi->scosc(),
            'u' => $this->csi->scorc(),
            default => $this->fallback?->csiDispatch($final, $params, $prefix, $intermediate),
        };
    }

    /**
     * DECSET/DECRST may carry several mode numbers in one sequence — e.g.
     * `CSI ? 1000 ; 1006 h` arms X11 button tracking AND SGR coordinate
     * reporting together. Dispatch every parameter, not just the first, so a
     * consumer can tell which encoding produced a later mouse byte sequence
     * instead of assuming a default. A default (missing) parameter maps to 0,
     * matching the single-mode behaviour this adapter has always had.
     *
     * @param list<int> $params
     */
    private function dispatchModes(array $params, int $prefix, bool $set): void
    {
        if ($params === []) {
            $params = [-1];
        }
        foreach ($params as $mode) {
            $mode = $mode === -1 ? 0 : $mode;
            if ($set) {
                $this->csi->decset($mode, $prefix);
            } else {
                $this->csi->decrst($mode, $prefix);
            }
        }
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        // The adapter models no ESC sequences itself; a wired fallback sees
        // the whole family unfiltered.
        $this->fallback?->escDispatch($final, $intermediate);
    }

    public function oscDispatch(string $data): void
    {
        // OSC 8 — hyperlink: `ESC ] 8 ; params ; uri ST`, where `params` is a
        // colon-separated key=value list (only `id=` is defined) and `uri` is
        // everything after the second `;` and may be empty (closes the link).
        // The backtick-terminated spelling without a params field — `ESC ] 8 ; uri ` ` `
        // (backtick-terminated) — is accepted: a trailing backtick is stripped
        // before parsing. A malformed `8;…` that has neither a second `;` nor
        // the backtick terminator CLOSES the current link instead of being
        // dropped: a swallowed close would bleed following text into a stale
        // hyperlink.
        if (str_starts_with($data, '8;')) {
            $backtick = str_ends_with($data, '`');
            $payload = $backtick ? substr($data, 0, -1) : $data;
            if (preg_match('/^8;([^;]*);(.*)$/s', $payload, $m) === 1) {
                $this->osc->hyperlink($m[2], $this->parseHyperlinkId($m[1]));
            } elseif ($backtick) {
                $this->osc->hyperlink(substr($payload, 2), '');
            } else {
                $this->osc->hyperlink('', '');
            }
            return;
        }

        // OSC 0/1/2 — window / icon title.
        // .* allows an empty payload (e.g. "2;" = clear title).
        if (preg_match('/^([0-2]);(.*)$/', $data, $m) === 1) {
            $this->osc->title($m[2]);
            return;
        }

        $this->fallback?->oscDispatch($data);
    }

    /**
     * Extract the `id=` value from an OSC 8 params field. The field is a
     * colon-separated list of key=value pairs; every key other than `id`
     * is ignored. Returns '' when no id is present.
     */
    private function parseHyperlinkId(string $params): string
    {
        if ($params === '') {
            return '';
        }
        foreach (explode(':', $params) as $pair) {
            if (str_starts_with($pair, 'id=')) {
                return substr($pair, 3);
            }
        }
        return '';
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        // DCS payloads belong to the fallback: the renderer-facing handler set
        // (CsiHandler/OscHandler) has no DCS surface.
        $this->fallback?->dcsDispatch($final, $params, $prefix, $intermediate, $data);
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->fallback?->sosPmApcDispatch($kind, $data);
    }
}

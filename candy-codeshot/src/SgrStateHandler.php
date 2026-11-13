<?php

declare(strict_types=1);

// candy-codeshot-058: this directive used to sit ABOVE declare() — the lib's
// only first-line-rule breaker. A leading comment before declare is legal
// PHP but reads as a file-level annotation; the ignore belongs to the code
// below, so it lives below.
// codacy ignore UndefinedVariable

namespace SugarCraft\CodeShot;

use SugarCraft\AnsiParser\Parser\SubparamsAwareHandler;
use SugarCraft\Core\Util\Width;

/**
 * Handler implementation for SGR (Select Graphic Rendition) state parsing.
 *
 * The state machine itself is sugar-ansi-parser's (the port of
 * charmbracelet/x/ansi); this handler owns only the rendition semantics:
 * SGR meaning follows ECMA-48 §14.1.1 and xterm's ctlseqs for the extended
 * colour spellings. There is no charmbracelet/vterm in this dependency set
 * (candy-codeshot-083 retired that phantom cite). This is a plain token
 * handler: it accumulates SGR state and forwards styled segments through the
 * callback given to its constructor — it is not a renderer and not a TEA
 * Program of its own.
 *
 * @internal
 */
final class SgrStateHandler implements SubparamsAwareHandler
{
    private SgrState $state;
    private string $textBuf;
    /** @var callable */
    private $flush;
    /** @var list<Segment> */
    private array $segments;

    /**
     * Running cell-count of the current line, decomposed so a tab needs no
     * re-measure (cs-05: the historic per-HT `Width::of($this->textBuf)` plus
     * a walk over EVERY flushed segment made a line of T tabs cost O(T·W) —
     * measured 16.4 s at 10k tabs, >230 s at 40k):
     *  - `$flushedCells` — width of every segment the flush callback has
     *    already passed over,
     *  - `$committedCells` — whole grapheme clusters closed in the pending
     *    buffer, plus folded tab padding (a space never continues a cluster),
     *  - `$lastCluster` — the still-open cluster at the buffer's end.
     *
     * The sum equals `Width::of()` over the line's plain text because both
     * sides walk the same splitter (`Width::nextCluster()`): the merge
     * decision is made once per incoming codepoint, at the same boundary the
     * whole-buffer scan makes it, so paint-side pads stay byte-identical to
     * the historic re-measure on every input — including combining marks,
     * regional-indicator pairs, emoji modifiers and ZWJ sequences.
     */
    private int $flushedCells = 0;
    private int $committedCells = 0;
    private string $lastCluster = '';

    /**
     * ECMA-48 colon continuation flags the parser PUSHED for the sequence
     * currently being dispatched ({@see SubparamsAwareHandler::setSubparams()}),
     * or the empty list when nothing has been pushed — in which case every
     * sequence is read in its flat `;` spelling.
     *
     * @var list<bool>
     */
    private array $subparams = [];

    public function __construct(SgrState &$state, string &$textBuf, callable $flush, array &$segments)
    {
        $this->state = &$state;
        $this->textBuf = &$textBuf;
        $this->flush = $flush;
        $this->segments = &$segments;
    }

    /**
     * {@inheritDoc}
     *
     * @param list<bool> $subparams
     */
    public function setSubparams(array $subparams): void
    {
        $this->subparams = $subparams;
    }

    public function printChar(string $rune): void
    {
        $this->textBuf .= $rune;
        $this->advanceColumn($rune);
    }

    /**
     * Fold one printed codepoint into the running cell-count.
     *
     * The parser delivers single codepoints; a codepoint continues the open
     * cluster exactly when `Width::nextCluster()` over the joined tail spans
     * the whole tail. Continuations grow `$lastCluster` without charging (the
     * cluster's width is its first codepoint's, charged once on close); a new
     * cluster closes — and pays for — the previous one.
     */
    private function advanceColumn(string $rune): void
    {
        if ($this->lastCluster !== '') {
            $merged = $this->lastCluster . $rune;
            if (Width::nextCluster($merged, 0) === $merged) {
                $this->lastCluster = $merged;
                return;
            }
            $this->committedCells += Width::of($this->lastCluster);
        }
        $this->lastCluster = $rune;
    }

    /**
     * Expand HT (0x09) to the next tab stop. The upstream parser routes every
     * Ground-state control byte here (its transition table sends 0x00-0x17 to
     * Execute); only HT has a visual meaning in a static render, so CR, BS,
     * DEL and friends stay dropped. The stop rule itself lives in
     * {@see TabStops} — shared with the budget-side walker.
     */
    public function execute(int $_byte): void
    {
        if ($_byte !== 0x09) {
            // Interface required; other control bytes carry no visual
            // meaning for an SGR-only handler.
            return;
        }

        // Count cells without flushing: the pad joins the pending text so
        // the run keeps one segment and the current SGR attributes (xterm
        // paints tabs in the colour in force). The count itself is O(1) —
        // printChar has already advanced it rune by rune.
        $pad = TabStops::pad($this->currentColumn());
        $this->committedCells += $pad;
        $this->textBuf .= str_repeat(' ', $pad);
    }

    /** Whole cells painted or pending on the current line. */
    private function currentColumn(): int
    {
        return $this->flushedCells + $this->committedCells + Width::of($this->lastCluster);
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        if (chr($final) !== 'm') {
            return;
        }

        // The flush closes the pending run into one segment: pay its still
        // open cells into the flushed counter before the callback empties
        // the buffer (cs-05 running count).
        $this->flushedCells += $this->committedCells + Width::of($this->lastCluster);
        $this->committedCells = 0;
        $this->lastCluster = '';

        ($this->flush)();
        // ECMA-48 default-parameter rule: an omitted parameter takes its
        // initial value, so the bare SGR forms all mean a full reset —
        // `ESC[m` (the shape `tput sgr0` actually ships) and `ESC[;m` are
        // `ESC[0m`. The sugar-ansi-parser front end flattens an omitted
        // slot to a -1 sentinel (its Parser.php docblock); a slot-less
        // dispatch arrives as the empty list. Only TOP-LEVEL slots default
        // to 0: -1 inside a `:` group is the skip marker extendedColour's
        // count rule consumes (a clamped `38:2:::5:6:7` would paint
        // '#000506', and a clamped `38:2::1` would repaint on the flat
        // re-read — both pinned by AnsiParserColonSubparametersTest).
        // `$subparams[$i] === true` means slot $i continues into slot $i+1
        // (a colon followed it), so slot $i is top level exactly when the
        // flag before it is not true.
        if ($params === []) {
            $params = [0];
        } else {
            foreach ($params as $index => $value) {
                $isTopLevel = $index === 0 || ($this->subparams[$index - 1] ?? false) !== true;
                if ($value < 0 && $isTopLevel) {
                    $params[$index] = 0;
                }
            }
        }
        $this->state = $this->applySgr($params, $this->state, $this->subparams);
    }

    public function escDispatch(int $_final, int $_intermediate): void
    {
        // Interface required; not needed for SGR-only handler.
    }

    public function oscDispatch(string $data): void
    {
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
    }

    public function sosPmApcDispatch(string $_kind, string $_data): void
    {
        // Interface required; SOS/PM/APC sequences not needed for SGR-only handler.
    }

    /**
     * @param list<int>  $params     Flattened SGR parameters; -1 marks an omitted one.
     * @param list<bool> $subparams  Continuation flags pushed via {@see self::setSubparams()}.
     */
    private function applySgr(array $params, SgrState $cur, array $subparams = []): SgrState
    {
        $fg = $cur->fg;
        $bg = $cur->bg;
        $bold = $cur->bold;
        $italic = $cur->italic;
        $underline = $cur->underline;

        $count = count($params);
        for ($i = 0; $i < $count; $i++) {
            $p = $params[$i];
            // Direct opcode dispatch (cs-24, -040): the old shape built a
            // closure per matched parameter and applied it through
            // variable-variable writes. The assignment targets are five plain
            // locals; writing them directly is the same semantics with no
            // closures, no string-keyed indirection, and nothing a typo can hide in.
            if ($p === 0) {
                $fg = $bg = null;
                $bold = $italic = $underline = false;
                continue;
            }
            if ($p === 1) {
                $bold = true;
                continue;
            }
            if ($p === 3) {
                $italic = true;
                continue;
            }
            if ($p === 4) {
                $underline = true;
                continue;
            }
            if ($p === 22) {
                $bold = false;
                continue;
            }
            if ($p === 23) {
                $italic = false;
                continue;
            }
            if ($p === 24) {
                $underline = false;
                continue;
            }
            if ($p === 39) {
                $fg = null;
                continue;
            }
            if ($p === 49) {
                $bg = null;
                continue;
            }
            if ($p >= 30 && $p <= 37) {
                $fg = AnsiParser::ANSI16[$p - 30] ?? null;
                continue;
            }
            if ($p >= 90 && $p <= 97) {
                $fg = AnsiParser::ANSI16[$p - 90 + 8] ?? null;
                continue;
            }
            if ($p >= 40 && $p <= 47) {
                $bg = AnsiParser::ANSI16[$p - 40] ?? null;
                continue;
            }
            if ($p >= 100 && $p <= 107) {
                $bg = AnsiParser::ANSI16[$p - 100 + 8] ?? null;
                continue;
            }
            if (($p === 38 || $p === 48) && isset($params[$i + 1])) {
                [$colour, $reached] = self::extendedColour($params, $subparams, $i);
                if ($colour !== null) {
                    if ($p === 38) {
                        $fg = $colour;
                    } else {
                        $bg = $colour;
                    }
                }
                $i = $reached;
                continue;
            }
        }
        return new SgrState($fg, $bg, $bold, $italic, $underline);
    }

    /**
     * Resolve an extended-colour parameter (38 foreground / 48 background) and
     * report how far into the parameter list it reached.
     *
     * xterm ctlseqs defines both SGR spellings, and ECMA-48 §14.1.1 makes the
     * second one legal:
     *
     *   `38;2;R;G;B`     flat — one component per parameter
     *   `38:2:CS:R:G:B`  grouped — CS is a colour-space id, usually left empty
     *   `38:5:N`         grouped 256-colour index
     *
     * Reading that colour-space id as the red component is exactly what turned
     * `38:2::80:160:240` into a garbage 18-hex value, so a grouped parameter is
     * parsed on its own terms and only falls back to the flat reading when the
     * group cannot yield a colour of its own.
     *
     * When nothing resolves, the start index comes back unchanged and the caller's
     * loop re-reads the group's slots as ordinary SGRs. That is historic behaviour:
     * strict ECMA-48 would consume the failed group, but changing it here would
     * repaint malformed input that this fix was not meant to touch.
     *
     * @param list<int>  $params
     * @param list<bool> $subparams
     * @return array{0:?string,1:int} `#rrggbb` (null when unresolved) and the index the scan reached
     */
    private static function extendedColour(array $params, array $subparams, int $start): array
    {
        $group = self::parameterGroup($params, $subparams, $start);

        if (count($group) > 1) {
            $colour = self::colourFromGroup($group);
            if ($colour !== null) {
                return [$colour, $start + count($group) - 1];
            }
        }

        return self::colourFromFlatParams($params, $start);
    }

    /**
     * The parameter starting at `$start` plus every value its own `:` separator
     * pulled along — i.e. one ECMA-48 §14.1.1 parameter group.
     *
     * @param list<int>  $params
     * @param list<bool> $subparams
     * @return list<int>
     */
    private static function parameterGroup(array $params, array $subparams, int $start): array
    {
        $group = [$params[$start]];
        for ($index = $start; ($subparams[$index] ?? false) === true; $index++) {
            $group[] = $params[$index + 1] ?? -1;
        }

        return $group;
    }

    /**
     * Colour carried by a single colon-separated parameter group.
     *
     * xterm counts the sub-parameters after the mode to decide whether the first
     * one is a colour-space id: four values mean `CS:R:G:B`, three mean a bare
     * `R:G:B`. Dropping the id slot is therefore tried first, and the tail is
     * re-read whole only when that leaves too few components to paint with.
     *
     * <p>`SugarCraft\AnsiInspect\Inspector` labels an SGR with the same count rule on
     * the same bytes — measured to agree on every colour-resolving shape both
     * suites pin — so a well-formed spec means one colour and one label. A
      * group that cannot resolve is the honest exception: this lib falls back to
      * the flat parameter read (`38:2::;1;2;3` paints `#000001`), the inspector
      * labels the truncated group and never guesses a colour. Both sides pin
      * that divergence literally — `testUnresolvableColonGroupFallsBackAcrossParameterBoundaries`
      * here, `ByteFidelityTest::testColonTruncatedExtendedColoursAreReportedAsTruncated`
      * in candy-ansi-inspect. A change to the count rule here must be mirrored there.
     *
     * @param list<int> $group `38` (or `48`) followed by its sub-parameters
     */
    private static function colourFromGroup(array $group): ?string
    {
        $mode = $group[1];
        $tail = array_slice($group, 2);

        if ($mode === 5) {
            foreach ($tail as $value) {
                if ($value >= 0) {
                    return self::paletteColour($value);
                }
            }
            return null;
        }

        if ($mode !== 2) {
            return null;
        }

        // Skip the colour-space id slot first; senders that leave it out
        // entirely still resolve, because the fallback reads the same tail.
        return self::rgbFrom(array_slice($tail, 1)) ?? self::rgbFrom($tail);
    }

    /**
     * First three supplied components of a direct-colour group.
     *
     * @param list<int> $values
     */
    private static function rgbFrom(array $values): ?string
    {
        $components = [];
        foreach ($values as $value) {
            if ($value < 0) {
                continue;
            }
            $components[] = $value;
            if (count($components) === 3) {
                return self::hex($components[0], $components[1], $components[2]);
            }
        }

        return null;
    }

    /**
     * The flat `38;5;N` / `38;2;R;G;B` spelling, as it has always been read.
     *
     * @param list<int> $params
     * @return array{0:?string,1:int}
     */
    private static function colourFromFlatParams(array $params, int $start): array
    {
        $mode = $params[$start + 1] ?? null;

        if ($mode === 5 && isset($params[$start + 2])) {
            return [self::paletteColour($params[$start + 2]), $start + 2];
        }

        if ($mode === 2 && isset($params[$start + 2], $params[$start + 3], $params[$start + 4])) {
            return [
                self::hex($params[$start + 2], $params[$start + 3], $params[$start + 4]),
                $start + 4,
            ];
        }

        return [null, $start];
    }

    /**
     * An xterm-256 palette colour, or null — meaning NO colour change —
     * when the index is outside the 256-entry table.
     *
     * xterm ignores an out-of-range `38;5;N` / `48;5;N` parameter rather
     * than repainting with the clamped neighbour, and the README's own
     * doctrine for such params is "parsed and ignored" (cs-23, -066: the
     * old 0..255 clamp was the undisclosed divergence — `38;5;999`
     * painted #eeeeee, a colour nobody asked for). The public door to the
     * same table is AnsiParser::xterm256ToHex(), which throws; one
     * decision, two honest surfaces.
     */
    private static function paletteColour(int $index): ?string
    {
        return ($index >= 0 && $index <= 255) ? AnsiParser::xterm256ToHex($index) : null;
    }

    /**
     * Assemble an `#rrggbb` triple, coercing each component to 8 bits.
     *
     * The parser caps a parameter at 65535 and `sprintf('%02x', …)` happily
     * emits three digits, so one oversized (or omitted, hence -1) component was
     * enough to produce an 18-character "colour" that no CSS engine accepts.
     */
    private static function hex(int $red, int $green, int $blue): string
    {
        return sprintf(
            '#%02x%02x%02x',
            self::clampComponent($red),
            self::clampComponent($green),
            self::clampComponent($blue),
        );
    }

    /** A colour component is 8 bits; an omitted parameter defaults to 0. */
    private static function clampComponent(int $value): int
    {
        return max(0, min(255, $value));
    }
}

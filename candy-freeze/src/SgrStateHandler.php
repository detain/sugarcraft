<?php

// codacy ignore UndefinedVariable
declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Ansi\Parser\SubparamsAwareHandler;

/**
 * Handler implementation for SGR (Select Graphic Rendition) state parsing.
 *
 * Mirrors charmbracelet/vterm's state machine logic inside a TEA-friendly
 * closure-based renderer.
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
    }

    public function execute(int $_byte): void
    {
        // Interface required; byte value not needed for SGR-only handler.
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        if (chr($final) !== 'm') {
            return;
        }

        ($this->flush)();
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
            $applied = match (true) {
                $p === 0  => fn() => ['fg' => null, 'bg' => null, 'bold' => false, 'italic' => false, 'underline' => false],
                $p === 1  => fn() => ['bold' => true],
                $p === 3  => fn() => ['italic' => true],
                $p === 4  => fn() => ['underline' => true],
                $p === 22 => fn() => ['bold' => false],
                $p === 23 => fn() => ['italic' => false],
                $p === 24 => fn() => ['underline' => false],
                $p === 39 => fn() => ['fg' => null],
                $p === 49 => fn() => ['bg' => null],
                default   => null,
            };
            if ($applied !== null) {
                $changes = $applied();
                foreach ($changes as $k => $v) {
                    $$k = $v;
                }
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
     * <p>`SugarCraft\Spark\Inspector` labels an SGR with the same count rule on
     * the same bytes — measured to agree on every colour-resolving shape both
     * suites pin — so a well-formed spec means one colour and one label. A
      * group that cannot resolve is the honest exception: this lib falls back to
      * the flat parameter read (`38:2::;1;2;3` paints `#000001`), the inspector
      * labels the truncated group and never guesses a colour. Both sides pin
      * that divergence literally — `testUnresolvableColonGroupFallsBackAcrossParameterBoundaries`
      * here, `ByteFidelityTest::testColonTruncatedExtendedColoursAreReportedAsTruncated`
      * in sugar-spark. A change to the count rule here must be mirrored there.
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

    /** An xterm-256 index, clamped into the table the renderer owns. */
    private static function paletteColour(int $index): string
    {
        return AnsiParser::xterm256ToHex(self::clampComponent($index));
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

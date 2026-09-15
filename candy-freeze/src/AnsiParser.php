<?php

// codacy ignore UndefinedVariable
declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Splits a single line of ANSI-styled text into typed {@see Segment}s
 * for {@see SvgRenderer}.
 *
 * Handles SGR foreground and background colours (16-color / 256-color
 * / 24-bit RGB) and the standard attribute flags (bold, italic,
 * underline). Background colours are passed through to segments for
 * per-segment rendering.
 *
 * Both separator spellings are understood for the extended colours — the
 * flat `38;2;R;G;B` and the ECMA-48 §14.1.1 grouped `38:2::R:G:B` form xterm
 * documents — because the colour-space id slot in the grouped form is not a
 * colour component.
 *
 * Other ANSI sequences (CSI cursor moves, OSC, etc.) pass through
 * silently — they have no visible effect in a static SVG.
 *
 * Internally delegates to candy-ansi's {@see Parser} state machine.
 */
final class AnsiParser
{
    /** xterm 16-color palette as hex strings, used for `\x1b[3{0-7}m`. */
    public const ANSI16 = [
        0  => '#000000', 1  => '#cd0000', 2  => '#00cd00', 3  => '#cdcd00',
        4  => '#0000ee', 5  => '#cd00cd', 6  => '#00cdcd', 7  => '#e5e5e5',
        8  => '#7f7f7f', 9  => '#ff0000', 10 => '#00ff00', 11 => '#ffff00',
        12 => '#5c5cff', 13 => '#ff00ff', 14 => '#00ffff', 15 => '#ffffff',
    ];

    /**
     * Parse one line of ANSI text into a list of styled segments.
     *
     * @return list<Segment>
     */
    public static function parse(string $line): array
    {
        $segments = [];
        $state = new SgrState();
        $textBuf = '';
        $flush = static function () use (&$segments, &$textBuf, &$state): void {
            if ($textBuf === '') {
                return;
            }
            $segments[] = new Segment(
                text:      $textBuf,
                fg:        $state->fg,
                bold:      $state->bold,
                italic:    $state->italic,
                underline: $state->underline,
                bg:        $state->bg,
            );
            $textBuf = '';
        };

        $handler = new SgrStateHandler($state, $textBuf, $flush, $segments);

        $parser = new Parser($handler);
        // The flattened `list<int>` a handler receives cannot distinguish
        // `38:2::80:160:240` (one parameter group) from `38;2;80;160;240`, so
        // the handler reads the parser's ECMA-48 sub-parameter flags to tell
        // them apart. Binding after construction: the parser needs the handler,
        // the handler needs the parser — neither can own the other's creation.
        $handler->bindParser($parser);
        $parser->feed($line);
        $parser->flush();
        $flush();

        return $segments;
    }

    public static function xterm256ToHex(int $i): string
    {
        if ($i < 16) {
            return self::ANSI16[$i] ?? '#ffffff';
        }
        if ($i >= 232) {
            $g = 8 + ($i - 232) * 10;
            return sprintf('#%02x%02x%02x', $g, $g, $g);
        }
        $idx = $i - 16;
        $levels = [0, 95, 135, 175, 215, 255];
        return sprintf(
            '#%02x%02x%02x',
            $levels[intdiv($idx, 36)],
            $levels[intdiv($idx, 6) % 6],
            $levels[$idx % 6],
        );
    }
}

/**
 * Handler implementation for SGR (Select Graphic Rendition) state parsing.
 *
 * Mirrors charmbracelet/vterm's state machine logic inside a TEA-friendly
 * closure-based renderer.
 *
 * @internal
 */
final class SgrStateHandler implements Handler
{
    private SgrState $state;
    private string $textBuf;
    /** @var callable */
    private $flush;
    /** @var list<Segment> */
    private array $segments;

    /**
     * Parser whose sub-parameter flags accompany the current dispatch, or null
     * when this handler is driven by something that reports no flags — in which
     * case every sequence is read in its flat `;` spelling.
     */
    private ?Parser $parser = null;

    public function __construct(SgrState &$state, string &$textBuf, callable $flush, array &$segments)
    {
        $this->state = &$state;
        $this->textBuf = &$textBuf;
        $this->flush = $flush;
        $this->segments = &$segments;
    }

    /**
     * Let this handler read {@see Parser::subparams()} while a CSI is in
     * flight, so colon sub-parameters keep their grouping.
     */
    public function bindParser(Parser $parser): void
    {
        $this->parser = $parser;
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
        $this->state = $this->applySgr($params, $this->state, $this->parser?->subparams() ?? []);
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
     * @param list<bool> $subparams  Continuation flags from {@see Parser::subparams()}.
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
                [$colour, $reached] = $this->extendedColour($params, $subparams, $i);
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
     * @param list<int>  $params
     * @param list<bool> $subparams
     * @return array{0:?string,1:int} `#rrggbb` (null when unresolved) and the index the scan reached
     */
    private function extendedColour(array $params, array $subparams, int $start): array
    {
        $group = $this->parameterGroup($params, $subparams, $start);

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
    private function parameterGroup(array $params, array $subparams, int $start): array
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
        return AnsiParser::xterm256ToHex(self::component($index));
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
            self::component($red),
            self::component($green),
            self::component($blue),
        );
    }

    /** A colour component is 8 bits; an omitted parameter defaults to 0. */
    private static function component(int $value): int
    {
        return max(0, min(255, $value));
    }
}


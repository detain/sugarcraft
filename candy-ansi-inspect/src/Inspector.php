<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect;

use SugarCraft\AnsiParser\Codec\Dcs;

/**
 * Walk a string of mixed text + ANSI escape sequences and split it into
 * {@see Segment}s. The decoder labels SGR codes (foreground, background,
 * attributes), CSI cursor moves, CSI erase/mode toggles, OSC titles,
 * and bracketed-paste / focus / mouse mode sequences. Anything it
 * doesn't recognise still gets a Segment with a generic `"CSI"` /
 * `"OSC"` / `"SS3"` label, so no sequence that reaches the segmenter is
 * dropped without a report. Bytes can still vanish further upstream, inside
 * sugar-ansi-parser, before this code ever sees a dispatch — `README.md` keeps the
 * closed list of every known case and `ByteFidelityTest` pins each one.
 *
 * Sequence *recognition* lives entirely in sugar-ansi-parser's {@see Parser} state
 * machine; this class only labels the sequences it dispatches, plus the
 * truncated tails {@see AnsiHandler} recovers at end of stream. There is no
 * scanning of the input stream of its own, so OSC/DCS/APC strings go through
 * the same machine as CSI rather than a separate fast path.
 */
final class Inspector
{
    /**
     * Parse $input into a list of segments. Plain text becomes
     * {@see TextSegment}; escape sequences become {@see SequenceSegment}.
     *
     * @return list<Segment>
     */
    public static function parse(string $input): array
    {
        return (new AnsiHandler())->parse($input);
    }

    /** Render parsed segments as a sequin-style report (one per line). */
    public static function report(string $input): string
    {
        $lines = [];
        foreach (self::parse($input) as $seg) {
            $lines[] = $seg->describe();
        }
        return implode("\n", $lines);
    }

    /**
     * Render parsed segments as a machine-readable JSON array string.
     *
     * Each entry: {"type": "text"|"sequence", "content": "...", "description": "..."}
     *
     * @return string JSON array string; each entry is {type, content, description}.
     */
    public static function reportAsJson(string $input): string
    {
        return self::encodeSegmentsJson(self::parse($input));
    }

    /**
     * JSON projection of an already-parsed segment list — the shared body of
     * reportAsJson() and the bin's `--json` streaming arm (finding 005: a
     * pipe under --json collects segments and encodes the same array shape).
     *
     * @param iterable<Segment> $segments
     */
    public static function encodeSegmentsJson(iterable $segments): string
    {
        $result = [];
        foreach ($segments as $seg) {
            $result[] = [
                'type' => $seg instanceof TextSegment ? 'text' : 'sequence',
                'content' => $seg->raw(),
                'description' => $seg->describe(),
            ];
        }
        // JSON_THROW_ON_ERROR ensures a typed JsonException instead of false-return
        // + TypeError against the : string return type.  JSON_INVALID_UTF8_SUBSTITUTE
        // replaces malformed UTF-8 bytes (e.g. in OSC titles / DCS sixel bodies) with
        // U+FFFD rather than causing encode failure.
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public static function describeCsi(string $params, string $final): string
    {
        // Bracketed paste, focus reporting, mouse modes — DEC private.
        if (isset($params[0]) && $params[0] === '?' && ($final === 'h' || $final === 'l')) {
            $on    = $final === 'h';
            $codes = explode(';', substr($params, 1));
            $names = array_map(static fn(string $c) => self::decPrivateName((int) $c), $codes);
            return ($on ? 'enable ' : 'disable ') . implode(', ', $names);
        }

        // Mouse EVENT reports (finding 006): the SGR (1006) and urxvt (1015)
        // encodings ride the same `M`/`m` finals the line-editing fall-through
        // below otherwise reads as "delete lines". The bare `ESC[M` X10 form
        // has no marker here at all — AnsiHandler arms for its three raw
        // payload bytes before describe ever sees it.
        if (
            ($final === 'M' || $final === 'm')
            && (str_starts_with($params, '<') || str_starts_with($params, '>'))
        ) {
            // modifyOtherKeys (xterm) also rides `>` … m: name it before the
            // mouse shapes claim it (finding 007's vocabulary).
            if ($final === 'm' && preg_match('/^>4[;:](\d+)$/', $params, $mok) === 1) {
                return 'set modifyOtherKeys ' . $mok[1] . ' (xterm)';
            }
            return self::describeMouse(
                $params[0] === '<' ? 'SGR' : 'urxvt',
                substr($params, 1),
                $final,
            );
        }

        // Device attributes (finding 020): DA1 `CSI c`, DA2 `CSI > c`, DA3
        // `CSI = c` — previously all three fell to the generic `CSI … c`.
        if ($final === 'c') {
            if ($params === '') {
                return 'request primary device attributes (DA1)';
            }
            if (str_starts_with($params, '>')) {
                return 'request secondary device attributes (DA2)' . (substr($params, 1) !== '' ? ' ' . substr($params, 1) : '');
            }
            if (str_starts_with($params, '=')) {
                return 'request tertiary device attributes (DA3)' . (substr($params, 1) !== '' ? ' ' . substr($params, 1) : '');
            }
            if (str_starts_with($params, '?')) {
                return 'device attributes query (private) ' . $params;
            }
            return 'device attributes reply ' . $params;
        }

        // DECRQM (mode-state query): `CSI [?] mode $p`.
        if (str_ends_with($params, '$') && $final === 'p') {
            $body = substr($params, 0, -1);
            $private = $body !== '' && $body[0] === '?';
            $mode = $private ? substr($body, 1) : $body;
            return ($private ? 'DEC private mode query (DECRQM) ' : 'mode query ') . $mode;
        }
        // DECRPM (mode-state reply): `CSI [?] mode ; state $y`.
        if (str_ends_with($params, '$') && $final === 'y') {
            $body = substr($params, 0, -1);
            return 'mode report (DECRPM) ' . $body;
        }
        // DECSCUSR (cursor shape): `CSI N SP q`.
        if (preg_match('/^\d* $/', $params) === 1 && $final === 'q') {
            $n = (int) trim($params);
            $shape = match ($n) {
                0, 1 => 'blinking block (default)',
                2 => 'steady block',
                3 => 'blinking underline',
                4 => 'steady underline',
                5 => 'blinking bar',
                6 => 'steady bar',
                default => "shape $n",
            };
            return 'cursor shape: ' . $shape;
        }
        // Kitty keyboard query: `CSI ? u` and replies.
        if ($params === '?' && $final === 'u') {
            return 'kitty keyboard query';
        }
        if (preg_match('/^\?\d+$/', $params) === 1 && $final === 'u') {
            return 'kitty keyboard reply, flags=' . substr($params, 1);
        }
        if ($final === 'u' && str_starts_with($params, '<')) {
            return 'pop kitty keyboard layers ' . substr($params, 1);
        }
        if ($final === 'u' && str_starts_with($params, '>')) {
            return 'push kitty keyboard flags ' . substr($params, 1);
        }
        // XTVERSION request: `CSI > 0 q` — both the glued spelling and the
        // space spelling (finding 020: the space spelling falls through the
        // DECSCUSR-shaped arm because the intermediate sits between the
        // `0` and the `q`, exactly like DECSCUSR's own `N SP q`).
        if (preg_match('/^>0 ?$/D', $params) === 1 && $final === 'q') {
            return 'request terminal version (XTVERSION)';
        }
        // OSC palette set/query and DSR.
        if ($params === '6' && $final === 'n') {
            return 'request cursor position (DSR-CPR)';
        }
        if ($params === '5' && $final === 'n') {
            return 'request operating status (DSR)';
        }
        // DECSTBM scrolling region: `CSI [top;bottom] r`.
        if ($final === 'r') {
            return $params === ''
                ? 'reset scrolling region'
                : 'set scrolling region ' . $params;
        }

        return match ($final) {
            // SGR is the PLAIN parameter space: no private marker, no
            // sub-parameter colon, and every group digit-or-empty (finding
            // 007). `ESC[?7m` / `ESC[>4;2m` / `ESC[:5m` are NOT SGR and must
            // not borrow its labels.
            'm' => self::describeMarkerFreeSgr($params),
            'A' => 'cursor up '    . ($params === '' ? '1' : $params),
            'B' => 'cursor down '  . ($params === '' ? '1' : $params),
            'C' => 'cursor right ' . ($params === '' ? '1' : $params),
            'D' => 'cursor left '  . ($params === '' ? '1' : $params),
            'E' => 'cursor next line '  . ($params === '' ? '1' : $params),
            'F' => 'cursor prev line '  . ($params === '' ? '1' : $params),
            'G' => 'cursor column ' . ($params === '' ? '1' : $params),
            'H' => 'cursor position ' . ($params === '' ? '1;1' : $params),
            'J' => 'erase display '   . ($params === '' ? '0' : $params),
            'K' => 'erase line '      . ($params === '' ? '0' : $params),
            'L' => 'insert lines '    . ($params === '' ? '1' : $params),
            'M' => 'delete lines '    . ($params === '' ? '1' : $params),
            'P' => 'delete chars ' . ($params === '' ? '1' : $params),
            'R' => 'cursor position report ' . $params,
            'S' => 'scroll up ' . ($params === '' ? '1' : $params),
            'T' => 'scroll down ' . ($params === '' ? '1' : $params),
            'b' => 'repeat preceding character ' . ($params === '' ? '1' : $params),
            's' => 'save cursor',
            'u' => 'restore cursor',
            'I' => 'tab forward ' . ($params === '' ? '1' : $params),
            'Z' => 'tab backward ' . ($params === '' ? '1' : $params),
            'g' => $params === '3' ? 'clear all tab stops' : 'clear tab stop',
            '@' => 'insert chars ' . ($params === '' ? '1' : $params),
            'f' => 'cursor position (HVP) ' . ($params === '' ? '1;1' : $params),
            'd' => 'line position absolute (VPR) ' . ($params === '' ? '1' : $params),
            'e' => 'column position absolute (HPR) ' . ($params === '' ? '1' : $params),
            'X' => 'erase chars (ECH) ' . ($params === '' ? '1' : $params),
            't' => 'window manipulation (XTWINOPS)' . ($params === '' ? '' : ' ' . $params),
            '~' => self::describeTilde($params),
            default => 'CSI ' . ($params === '' ? '' : $params . ' ') . $final,
        };
    }

    private static function describeSgr(string $params): string
    {
        if ($params === '' || $params === '0') {
            return 'reset';
        }
        $codes = explode(';', $params);
        $parts = [];
        for ($i = 0, $n = count($codes); $i < $n; $i++) {
            $codeStr = $codes[$i];
            $code = (int) $codeStr;
            // An underline style variant (`4:2`) always rides inside its own
            // parameter group; a bare `4` is the ordinary underline and the
            // switch below names it. An earlier revision also peeked at the
            // NEXT element for a `4:N`, but groups never split across a
            // semicolon that way — and `(int)` on the borrowed group re-read
            // `4;4:3` as style 4 (dotted) instead of two effects (underline,
            // underline curly), so step 10.16 removed the heuristic outright.
            if (preg_match('/^4:(\d+)$/', $codeStr, $underlined) === 1) {
                $parts[] = self::underlineStyleName((int) $underlined[1]);
                continue;
            }
            // 38;5;n (256-color fg) and 38;2;r;g;b (truecolor fg) — and 58,
            // the underline-colour twin of both, with the same sub-parameter
            // grammar in both spellings (finding 008; 59 defaults it and is
            // named in sgrName).
            if ($code === 38 || $code === 48 || $code === 58) {
                $kind = match ($code) { 38 => 'foreground', 48 => 'background', default => 'underline colour' };
                // Colon spelling (`38:5:n`, `38:2::r:g:b`) carries the whole
                // colour spec in one parameter group (ECMA-48 §14.1.1); the
                // semicolon spelling spreads it over adjacent parameters.
                if (str_contains($codeStr, ':') === true) {
                    $parts[] = self::describeColonColour($kind, $codeStr);
                    continue;
                }
                $sub  = (int) ($codes[$i + 1] ?? 0);
                if ($sub === 5) {
                    if (isset($codes[$i + 2]) === false) {
                        $parts[] = "$kind truncated 256-color";
                        $i += 1; // Only the sub (5) was present.
                    } else {
                        $parts[] = sprintf('%s 256-color %d', $kind, (int) $codes[$i + 2]);
                        $i += 2;
                    }
                } elseif ($sub === 2) {
                    if (isset($codes[$i + 2]) === false || isset($codes[$i + 3]) === false || isset($codes[$i + 4]) === false) {
                        $parts[] = "$kind truncated truecolor";
                        // Advance past only the params that were present.
                        $present = 1; // the '2' sub-param itself.
                        if (isset($codes[$i + 2]) === true) { $present++; }
                        if (isset($codes[$i + 3]) === true) { $present++; }
                        if (isset($codes[$i + 4]) === true) { $present++; }
                        $i += $present;
                    } else {
                        $parts[] = sprintf('%s rgb(%d,%d,%d)', $kind,
                            (int) $codes[$i + 2],
                            (int) $codes[$i + 3],
                            (int) $codes[$i + 4]);
                        $i += 4;
                    }
                } else {
                    $parts[] = "$kind unknown";
                    $i += 1;
                }
                continue;
            }
            $parts[] = self::sgrName($code);
        }
        return implode(', ', $parts);
    }

    /**
     * Label an extended-colour parameter group written with ECMA-48 §14.1.1
     * colon sub-parameters — `38:5:n` for 256-colour, `38:2:cs:r:g:b` for
     * direct colour.
     *
     * xterm reserves the first sub-parameter after the mode for a colour-space
     * id (emitters almost always leave it empty), which is why the components
     * are NOT simply the tail of the group: reading the id as red is what turns
     * `38:2::80:160:240` into a wrong colour.
     *
     * <p>The slot may also be omitted entirely, and the two spellings are
     * genuinely ambiguous on their face — `38:2:1:2:3` is either CS 1 with a
     * missing blue, or bare R:G:B. The rule applied here (and in
     * `SugarCraft\CodeShot\SgrStateHandler`, measured to agree on every
     * colour-resolving shape both suites pin) is xterm's count rule: four
     * sub-parameters after the mode are `cs:r:g:b`, three are bare `r:g:b`. A
     * future change to one parser's rule must be mirrored in the other, or the
     * same bytes will mean different colours in the inspector and in the
     * rendered output. A group that resolves in neither lib may still be
     * *described* differently: this one labels what the bytes say
     * (`38:2::;1;2;3` is a truncated truecolor group and no colour is
     * invented for it), candy-codeshot falls back to reading the flat parameter
     * list and paints `#000001`. Both sides pin that divergence literally:
     * `ByteFidelityTest::testColonTruncatedExtendedColoursAreReportedAsTruncated`
     * here, `AnsiParserColonSubparametersTest::testUnresolvableColonGroupFallsBackAcrossParameterBoundaries`
     * there.
     */
    private static function describeColonColour(string $kind, string $group): string
    {
        $subs = explode(':', $group);
        array_shift($subs);
        $mode = (int) ($subs[0] ?? -1);
        $rest = array_slice($subs, 1);
        $filled = static fn(array $values): array => array_values(array_filter($values, static fn(string $v): bool => $v !== ''));

        if ($mode === 5) {
            $index = $filled($rest);
            return $index === []
                ? "$kind truncated 256-color"
                : sprintf('%s 256-color %d', $kind, (int) $index[0]);
        }

        if ($mode === 2) {
            $components = $filled(array_slice($rest, 1));
            if (count($components) < 3) {
                // No colour-space id slot to skip — the components start at the
                // first sub-parameter after the mode.
                $components = $filled($rest);
            }
            if (count($components) < 3) {
                return "$kind truncated truecolor";
            }
            return sprintf(
                '%s rgb(%d,%d,%d)',
                $kind,
                (int) $components[0],
                (int) $components[1],
                (int) $components[2],
            );
        }

        return "$kind unknown";
    }

    private static function sgrName(int $code): string
    {
        return match (true) {
            $code === 0  => 'reset',
            $code === 1  => 'bold',
            $code === 2  => 'faint',
            $code === 3  => 'italic',
            $code === 4  => 'underline',
            $code === 5  => 'blink',
            $code === 7  => 'reverse',
            $code === 8  => 'conceal',
            $code === 9  => 'strikethrough',
            $code === 22 => 'no bold/faint',
            $code === 23 => 'no italic',
            $code === 24 => 'no underline',
            $code === 25 => 'no blink',
            $code === 27 => 'no reverse',
            $code === 28 => 'no conceal',
            $code === 29 => 'no strikethrough',
            $code === 26 => 'proportional font off',
            $code === 50 => 'proportional font (character spacing)',
            $code === 51 => 'framed',
            $code === 52 => 'encircled',
            $code === 53 => 'overlined',
            $code === 54 => 'framed/encircled off',
            $code === 55 => 'overlined off',
            $code === 59 => 'underline colour default',
            $code >= 60 && $code <= 65
                         => 'off extended-underline band (cancels ' . ($code - 39) . ')',
            $code === 73 => 'superscript',
            $code === 74 => 'subscript',
            $code === 75 => 'super/subscript off',
            $code >= 30 && $code <= 37
                         => 'foreground ' . self::ansiName($code - 30),
            $code === 39 => 'foreground default',
            $code >= 40 && $code <= 47
                         => 'background ' . self::ansiName($code - 40),
            $code === 49 => 'background default',
            $code >= 90 && $code <= 97
                         => 'foreground bright ' . self::ansiName($code - 90),
            $code >= 100 && $code <= 107
                         => 'background bright ' . self::ansiName($code - 100),
            default      => "SGR $code",
        };
    }

    private static function ansiName(int $i): string
    {
        return ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'][$i] ?? 'unknown';
    }

    /**
     * SGR routing guard (finding 007): `describeSgr` may only be reached by a
     * plain parameter space — no private marker (`?`, `<`, `>`, `=`), no
     * leading colon, every semicolon group digit-or-empty. Anything else
     * gets an honest generic that echoes the marker instead of inventing an
     * "SGR …" label for bytes that were never SGR.
     */
    private static function describeMarkerFreeSgr(string $params): string
    {
        if ($params === '') {
            return 'SGR reset';
        }
        $first = $params[0];
        if ($first === '?') {
            return 'private SGR ' . $params;
        }
        if (!ctype_digit($first)) {
            return 'SGR-marker ' . $params;
        }
        foreach (explode(';', $params) as $group) {
            if ($group !== '' && preg_match('/^\d+(?::\d*)*$/', $group) !== 1) {
                return 'SGR-marker ' . $params;
            }
        }
        return 'SGR ' . self::describeSgr($params);
    }

    /**
     * SGR (1006) mouse event decode (finding 006). Wire semantics:
     * `CSI < code ; x ; y M/m`, where code = button (0-2 left/mid/right,
     * 3/4 wheel-up/down, 5 none) + 4 shift + 8 meta + 16 ctrl + 32 motion,
     * and the final byte is the press/release edge. urxvt (1015) reports a
     * raw X10-style button number with no agreed modifier algebra, so its
     * code is echoed rather than invented.
     */
    private static function describeMouse(string $encoding, string $params, string $final): string
    {
        $codes = explode(';', $params);
        if (count($codes) < 3 || preg_match('/^\d+$/', $codes[0]) !== 1) {
            return 'mouse ' . $encoding . ' event ' . self::sanitizeLabelBytes($params) . ' ' . $final;
        }
        $button = (int) $codes[0];
        if ($encoding !== 'SGR') {
            return 'mouse ' . $encoding . ' ' . ($final === 'm' ? 'release' : 'press')
                . ' code' . $button . ' @' . (int) $codes[1] . ',' . (int) $codes[2];
        }
        if (($button & ~63) !== 0) {
            return 'mouse SGR event ' . self::sanitizeLabelBytes($params) . ' ' . $final;
        }
        $motion = ($button & 32) !== 0;
        $modifiers = '';
        if (($button & 4) !== 0) {
            $modifiers .= ' +shift';
        }
        if (($button & 8) !== 0) {
            $modifiers .= ' +meta';
        }
        if (($button & 16) !== 0) {
            $modifiers .= ' +ctrl';
        }
        $number = $button & ~60;
        $name = match ($number) {
            3 => 'wheel-up',
            4 => 'wheel-down',
            default => 'btn' . $number,
        };
        $state = $final === 'm' ? 'release' : ($motion ? 'motion' : 'press');
        return 'mouse ' . $encoding . ' ' . $state . ' ' . $name . ' @' . (int) $codes[1] . ',' . (int) $codes[2] . $modifiers;
    }

    /**
     * X10 mouse event (finding 006): the three raw bytes that trail `ESC[M`,
     * each offset by 32. Low bits 0-2 select button (3 = release), bit 4
     * shift, bit 8 meta, bit 16 ctrl, bit 5 drag, the 64 band wheels;
     * coordinates are byte-minus-31 (1-based).
     */
    public static function describeMouseX10(string $payload): string
    {
        $button = ord($payload[0]) - 32;
        $x = ord($payload[1]) - 31;
        $y = ord($payload[2]) - 31;
        $modifiers = '';
        if (($button & 4) !== 0) {
            $modifiers .= ' +shift';
        }
        if (($button & 8) !== 0) {
            $modifiers .= ' +meta';
        }
        if (($button & 16) !== 0) {
            $modifiers .= ' +ctrl';
        }
        $button &= ~28;
        if (($button & 64) !== 0) {
            $kind = match ($button & 3) {
                0 => 'wheel-up',
                1 => 'wheel-down',
                default => 'wheel-release',
            };
        } elseif (($button & 3) === 3) {
            $kind = 'release';
        } elseif (($button & 32) !== 0) {
            $kind = 'drag btn' . ($button & 3);
        } else {
            $kind = 'press btn' . ($button & 3);
        }
        return 'mouse X10 ' . $kind . ' @' . $x . ',' . $y . $modifiers;
    }

    private static function describeTilde(string $params): string
    {
        return match ($params) {
            '1', '7'  => 'Home',
            '2'       => 'Insert',
            '3'       => 'Delete',
            '4', '8'  => 'End',
            '5'       => 'PageUp',
            '6'       => 'PageDown',
            '11'      => 'F1',
            '12'      => 'F2',
            '13'      => 'F3',
            '14'      => 'F4',
            '15'      => 'F5',
            '17'      => 'F6',
            '18'      => 'F7',
            '19'      => 'F8',
            '20'      => 'F9',
            '21'      => 'F10',
            '23'      => 'F11',
            '24'      => 'F12',
            '200'     => 'bracketed paste start',
            '201'     => 'bracketed paste end',
            default   => "CSI $params~",
        };
    }

    private static function decPrivateName(int $code): string
    {
        return match ($code) {
            1    => 'cursor keys application mode (DECCKM)',
            6    => 'origin mode (DECOM)',
            7    => 'auto wrap',
            12   => 'cursor blink',
            25   => 'cursor visibility',
            47   => 'alternate screen (legacy)',
            1000 => 'mouse press/release',
            1002 => 'mouse cell motion',
            1003 => 'mouse all motion',
            1004 => 'focus reporting',
            1005 => 'UTF-8 mouse encoding (legacy)',
            1006 => 'mouse SGR encoding',
            1015 => 'mouse urxvt encoding',
            1047 => 'alternate screen (no save)',
            1048 => 'save/restore cursor',
            1049 => 'alternate screen',
            2004 => 'bracketed paste',
            2026 => 'synchronized output',
            2027 => 'unicode (grapheme cluster) mode',
            2031 => 'focused-output mode',
            default => "DEC ?$code",
        };
    }

    public static function describeOsc(string $payload): string
    {
        if (preg_match('/^(\d+);(.*)$/s', $payload, $m) === 1) {
            $safe = self::sanitizeLabelBytes($m[2]);
            return match ($m[1]) {
                '0', '2'  => 'set window title to "' . $safe . '"',
                '1'       => 'set icon name to "' . $safe . '"',
                '4'       => 'palette ' . $safe,
                '7'       => 'cwd ' . $safe,
                // OSC 8 carries `params ; URI` — split them (finding 020) so a
                // capture shows where the link actually goes, not one blob.
                '8'       => self::describeOscHyperlink($m[2]),
                '9'       => str_starts_with($m[2], '4;')
                                ? 'progress ' . self::sanitizeLabelBytes(substr($m[2], 2))
                                : 'iTerm2 ' . $safe,
                // `?` payload is a QUERY, not a set — printing "set … colour ?"
                // inverted the direction of the conversation (finding 020).
                '10'      => $m[2] === '?' ? 'query foreground colour' : 'set foreground colour ' . $safe,
                '11'      => $m[2] === '?' ? 'query background colour' : 'set background colour ' . $safe,
                '12'      => $m[2] === '?' ? 'query cursor colour' : 'set cursor colour ' . $safe,
                '52'      => 'clipboard ' . $safe,
                '104'     => 'reset colour palette' . ($m[2] === '' ? '' : ' register ' . $safe),
                '110'     => 'reset foreground colour',
                '111'     => 'reset background colour',
                '112'     => 'reset cursor colour',
                '133'     => 'shell integration (OSC 133) ' . $safe,
                '1337'    => 'iTerm2 private sequence (OSC 1337) ' . $safe,
                default   => 'OSC ' . self::sanitizeLabelBytes($payload),
            };
        }
        if (in_array($payload, ['104', '110', '111', '112'], true)) {
            return match ($payload) {
                '104' => 'reset colour palette',
                '110' => 'reset foreground colour',
                '111' => 'reset background colour',
                '112' => 'reset cursor colour',
            };
        }
        // Regex-miss payload (no `digit;` prefix) is as attacker-controlled as
        // the matched branches — sanitize before it reaches the report label.
        return 'OSC ' . self::sanitizeLabelBytes($payload);
    }

    /**
     * OSC 8 payload split: `params ; URI` (both may legitimately be empty —
     * `8;;URL` is the standard close-hyperlink form).
     */
    private static function describeOscHyperlink(string $body): string
    {
        $split = explode(';', $body, 2);
        $params = $split[0];
        $uri = $split[1] ?? '';
        return 'hyperlink'
            . ($params === '' ? '' : ' (' . self::sanitizeLabelBytes($params) . ')')
            . ($uri === '' ? ' (no URI)' : ' to ' . self::sanitizeLabelBytes($uri));
    }

    /**
     * Decode DCS payloads — XTVERSION reply, DECRQSS, DECRPSS, sixel, tmux
     * passthrough.
     *
     * Compatibility shim for callers that hold only the concatenated payload
     * string. Classification now lives in sugar-ansi-parser's shared
     * {@see Dcs} decoder ({@see describeDcsParts()}); this entry point peels
     * the prelude bytes back off the payload before delegating. Callers that
     * have the parser's real dispatch fields MUST prefer
     * {@see describeDcsParts()}: the peel cannot distinguish a leading '$'
     * that was a DECRQSS intermediate from sixel data that merely begins
     * with '$', and cannot recover parameter slots at all.
     *
     * 2026-10-23 (r1024/inspect): migrated onto the shared decoder. The
     * hand-rolled branch list that lived here classified only four shapes,
     * checked the '$' sixel/DECRQSS pair in payload order (so `ESC P $ q …`
     * DECRQSS requests were labelled "sixel image"), and had no notion of
     * tmux passthrough or of the final-byte dispatch that streaming sixel
     * now really performs.
     */
    public static function describeDcs(string $payload, int $final = 0): string
    {
        // Legacy byte-loop shape: the whole '>|' introducer sits inside the
        // payload and no final byte was ever threaded through.
        if ($final === 0 && str_starts_with($payload, '>|')) {
            return 'terminal version (XTVERSION reply): ' . self::sanitizeLabelBytes(substr($payload, 2));
        }
        // Legacy synthetic DECRPSS shape: '$$request;reply' carried both
        // operands inside the payload because no parameter array existed.
        // Real dispatch wires reach DECRPSS through describeDcsParts() with
        // the true params slot; this leg only serves direct payload callers.
        if ($final === ord('r') && str_starts_with($payload, '$$')) {
            $operands = explode(';', substr($payload, 2));
            if (count($operands) >= 2) {
                return 'DECRPSS reply ' . self::sanitizeLabelBytes($operands[0]) . '$r' . self::sanitizeLabelBytes($operands[1]) . '$p';
            }
        }

        // Peel the prelude in the order AnsiHandler::dcsDispatch() builds it:
        // intermediate byte (0x20-0x2F) first, then private-marker prefix
        // (0x3C-0x3F). Anything else is payload data.
        $intermediate = 0;
        $prefix = 0;
        $data = $payload;
        if ($data !== '' && (($byte = ord($data[0])) >= 0x20) && $byte <= 0x2F) {
            $intermediate = $byte;
            $data = substr($data, 1);
        }
        if ($data !== '' && (($byte = ord($data[0])) >= 0x3C) && $byte <= 0x3F) {
            $prefix = $byte;
            $data = substr($data, 1);
        }

        return self::describeDcsParts($final, [], $prefix, $intermediate, $data, $payload);
    }

    /**
     * Classify a DCS body from the parser's true dispatch fields using the
     * shared {@see Dcs} decoder, rendering the label in this inspector's
     * house spelling: attacker-controlled payload bytes go through
     * {@see sanitizeLabelBytes()} (named C0/C1 tokens), the same discipline
     * as the OSC/APC labels, so a captured reply can't inject control bytes
     * into the inspector's own output.
     *
     * $rawPayload, when given, is the concatenated prelude+parameter+data
     * string the handler rebuilds raw bytes from; the generic fallback
     * echoes it so parameter and intermediate bytes never vanish from an
     * unclassified DCS label. Sixel/tmux byte counts measure the true data
     * payload, not the prelude.
     */
    public static function describeDcsParts(
        int $final,
        array $params,
        int $prefix,
        int $intermediate,
        string $data,
        string $rawPayload = '',
    ): string {
        $echo = $rawPayload !== '' ? $rawPayload : $data;

        return match (Dcs::parse($final, $params, $prefix, $intermediate, $data)->kind()) {
            Dcs::KIND_XTVERSION => 'terminal version (XTVERSION reply): ' . self::sanitizeLabelBytes($data),
            Dcs::KIND_SIXEL => 'sixel image (' . strlen($data) . ' bytes)',
            Dcs::KIND_DECRQSS => 'DECRQSS request ' . self::sanitizeLabelBytes($data),
            Dcs::KIND_DECRPSS => 'DECRPSS reply '
                . self::sanitizeLabelBytes((string) ($params[0] ?? -1)) . '$r' . self::sanitizeLabelBytes($data),
            Dcs::KIND_TMUX => 'tmux passthrough (' . strlen($data) . ' bytes)',
            Dcs::KIND_TRUNCATED => 'DCS truncated (no final byte; ' . strlen($echo) . ' bytes collected)',
            default => 'DCS ' . chr($final) . self::sanitizeLabelBytes($echo),
        };
    }

    /** Decode APC payloads — CandyZone markers, kitty graphics. */ // @rename-keep legacy wire token / historical log
    public static function describeApc(string $payload): string
    {
        if (str_starts_with($payload, 'candyzone:')) { // @rename-keep legacy wire token / historical log
            return 'CandyZone marker ' . self::sanitizeLabelBytes(substr($payload, strlen('candyzone:'))); // @rename-keep legacy wire token / historical log
        }
        if (str_starts_with($payload, 'G')) {
            return 'kitty graphics (' . strlen($payload) . ' bytes)';
        }
        return 'APC ' . self::sanitizeLabelBytes($payload);
    }

    public static function describeSs3(string $final): string
    {
        return match ($final) {
            'P' => 'F1',
            'Q' => 'F2',
            'R' => 'F3',
            'S' => 'F4',
            'A' => 'cursor up',
            'B' => 'cursor down',
            'C' => 'cursor right',
            'D' => 'cursor left',
            'H' => 'Home',
            'F' => 'End',
            default => "SS3 $final",
        };
    }

    public static function describeEsc(string $byte): string
    {
        return match ($byte) {
            '7'  => 'save cursor (DECSC)',
            '8'  => 'restore cursor (DECRC)',
            '=' => 'application keypad mode',
            '>' => 'normal keypad mode',
            'D' => 'index (move cursor down)',
            'M' => 'reverse index (move cursor up)',
            'E' => 'next line',
            // The 7-bit spellings of the SOS / PM / ST C1 controls. A bare
            // `ESC X` (or `ESC ^`) never opens a string long enough to
            // dispatch — it flushes empty at end of stream — but naming the
            // introducer here is what step 10.16 asks the Inspector to do.
            'X' => 'start of string (SOS)',
            '^' => 'privacy message (PM)',
            '\\' => 'string terminator (ST)',
            'c' => 'reset to initial state',
            default => 'ESC ' . $byte,
        };
    }

    /**
     * Label a sequence the stream never terminated (ECMA-48 §5.6 escape
     * sequences run introducer → parameters → intermediates → final byte, and
     * the final byte is what completes them).
     *
     * Naming the family from the introducer keeps the report honest: the bytes
     * are real, they simply never formed a complete sequence.
     */
    public static function describeTruncated(string $bytes): string
    {
        $family = match (true) {
            str_starts_with($bytes, "\x1b[") => 'CSI',
            str_starts_with($bytes, "\x1bP") => 'DCS',
            str_starts_with($bytes, "\x1b]") => 'OSC',
            str_starts_with($bytes, "\x1b_") => 'APC',
            str_starts_with($bytes, "\x1bX") => 'SOS',
            str_starts_with($bytes, "\x1b^") => 'PM',
            str_starts_with($bytes, "\x1bO") => 'SS3',
            str_starts_with($bytes, "\x1b") => 'ESC',
            // The 8-bit C1 spellings that can actually reach end of stream
            // unterminated: 0x9B opens the CSI family and 0x90 the DCS family
            // directly (no ESC rewrite by then — the tail carries the raw
            // byte). The other C1 openers flush their string instead of
            // leaving a tail.
            str_starts_with($bytes, "\x9b") => 'CSI',
            str_starts_with($bytes, "\x90") => 'DCS',
            default => 'escape',
        };

        return 'truncated ' . $family . ' (unterminated)';
    }

    /**
     * Name an SGR underline style sub-parameter (`4:n`, ECMA-48). Mirrors the
     * SugarCraft\Vterm\Sgr\UnderlineStyle enum shipped by sugar-vterm step 07.05 —
     * candy-ansi-inspect carries no sugar-vterm dependency, so this table is the local
     * reflection of that enum and must be kept in step with it.
     */
    private static function underlineStyleName(int $n): string
    {
        return match ($n) {
            0 => 'no underline',
            1 => 'underline single',
            2 => 'underline double',
            3 => 'underline curly',
            4 => 'underline dotted',
            5 => 'underline dashed',
            default => 'underline style ' . $n,
        };
    }

    /**
     * Replace C0, DEL and C1 control bytes in a label interpolation with visible tokens.
     *
     * Prevents an embedded ESC in a captured OSC/APC/DCS payload from re-arming
     * a sequence when the report is rendered to a live terminal. The C1 range
     * (0x80–0x9F) carries the 8-bit spellings of the very controls a report is
     * about — CSI (0x9B), OSC (0x9D), ST (0x9C) — and sugar-ansi-parser collects them
     * verbatim into string payloads, so a captured 0x9B replayed raw inside a
     * label would re-arm a CSI parser on any 8-bit-capable downstream terminal.
     *
     * The C1 sweep is UTF-8 aware: the leading alternation consumes every
     * well-formed rune (ASCII printable plus 2–4 byte sequences) and refuses it
     * with `(*SKIP)(*FAIL)`, so continuation bytes that merely *look* like C1
     * (`€` = `E2 82 AC`, `一` = `E4 B8 80`) survive intact inside a title and
     * only a stand-alone 0x80–0x9F byte — which no valid rune can begin with —
     * is tokenised. Without this, neutralising injection would mangle every
     * non-English OSC window title, trading a security fix for a fidelity bug.
     *
     * Public since M1-fresh: the CLI refusal paths echo user-controlled argv
     * through this seam, so a planted escape sequence is shown tokenised rather
     * than replayed at the terminal.
     *
     * @param string $s Raw payload string interpolated into a human-readable label.
     */
    public static function sanitizeLabelBytes(string $s): string
    {
        return preg_replace_callback(
            '/(?:[\x20-\x7E]|[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3})(*SKIP)(*FAIL)|[\x00-\x1F\x7F-\x9F]/',
            static fn(array $m): string => match ($m[0][0]) {
                "\x1b" => 'ESC',
                "\x00" => 'NUL', "\x01" => 'SOH', "\x02" => 'STX', "\x03" => 'ETX',
                "\x04" => 'EOT', "\x05" => 'ENQ', "\x06" => 'ACK', "\x07" => 'BEL',
                "\x08" => 'BS',  "\x09" => 'HT',  "\x0a" => 'LF',  "\x0b" => 'VT',
                "\x0c" => 'FF',  "\x0d" => 'CR',  "\x0e" => 'SO',  "\x0f" => 'SI',
                "\x10" => 'DLE', "\x11" => 'DC1', "\x12" => 'DC2', "\x13" => 'DC3',
                "\x14" => 'DC4', "\x15" => 'NAK', "\x16" => 'SYN', "\x17" => 'ETB',
                "\x18" => 'CAN', "\x19" => 'EM',  "\x1a" => 'SUB', "\x1c" => 'FS',
                "\x1d" => 'GS',  "\x1e" => 'RS',  "\x1f" => 'US',  "\x7f" => 'DEL',
                "\x84" => 'IND', "\x85" => 'NEL', "\x88" => 'HTS', "\x8d" => 'RI',
                "\x8e" => 'SS2', "\x8f" => 'SS3', "\x90" => 'DCS', "\x98" => 'SOS',
                "\x9b" => 'CSI', "\x9c" => 'ST',  "\x9d" => 'OSC', "\x9e" => 'PM',
                "\x9f" => 'APC',
                default => sprintf('\\x%02X', ord($m[0])),
            },
            $s,
        );
    }
}

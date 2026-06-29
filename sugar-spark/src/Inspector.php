<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

/**
 * Walk a string of mixed text + ANSI escape sequences and split it into
 * {@see Segment}s. The decoder labels SGR codes (foreground, background,
 * attributes), CSI cursor moves, CSI erase/mode toggles, OSC titles,
 * and bracketed-paste / focus / mouse mode sequences. Anything it
 * doesn't recognise still gets a Segment with a generic `"CSI"` /
 * `"OSC"` / `"SS3"` label so the output never silently swallows
 * sequences.
 *
 * Uses candy-ansi's {@see Parser} state machine for CSI sequences and
 * simple ESC sequences, with a fast pre-scan for complex multi-byte
 * sequences (OSC/DCS/APC) that need exact raw-byte preservation.
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
        $segments = self::parse($input);
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
        // XTVERSION request: `CSI > 0 q`.
        if ($params === '>0' && $final === 'q') {
            return 'request terminal version (XTVERSION)';
        }
        // OSC palette set/query and DSR.
        if ($params === '6' && $final === 'n') {
            return 'request cursor position (DSR-CPR)';
        }
        // DECSTBM scrolling region: `CSI [top;bottom] r`.
        if ($final === 'r') {
            return $params === ''
                ? 'reset scrolling region'
                : 'set scrolling region ' . $params;
        }

        return match ($final) {
            'm' => 'SGR ' . self::describeSgr($params),
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
            '~' => self::describeTilde($params),
            default => 'CSI ' . ($params === '' ? '' : $params . ' ') . $final,
        };
    }

    private static function describeSgr(string $params): string
    {
        if ($params === '' || $params === '0') {
            return 'reset';
        }
        // Handle SGR underline styles 4:1 through 4:5 (single, double, curly, dotted, dashed).
        if (preg_match('/^(\d+):(\d+)$/', $params, $m) === 1) {
            $main = (int) $m[1];
            $sub = (int) $m[2];
            if ($main === 4) {
                return self::underlineStyleName($sub);
            }
            return 'SGR ' . $params;
        }
        $codes = explode(';', $params);
        $parts = [];
        for ($i = 0, $n = count($codes); $i < $n; $i++) {
            $codeStr = $codes[$i];
            $code = (int) $codeStr;
            // Handle SGR 4:N underline styles - either standalone "4:2" or embedded in codeStr "4:2".
            if ($code === 4) {
                // Check if current codeStr is "4:N" format (sub-param embedded in same element).
                if (preg_match('/^4:(\d+)$/', $codeStr, $m) === 1) {
                    $sub = (int) $m[1];
                    $parts[] = self::underlineStyleName($sub);
                    continue;
                }
                // Also check if next element is "4:N" format.
                if (isset($codes[$i + 1]) && preg_match('/^4:(\d+)$/', $codes[$i + 1], $m) === 1) {
                    $sub = (int) $codes[$i + 1];
                    $parts[] = self::underlineStyleName($sub);
                    $i++; // Skip the sub-parameter.
                    continue;
                }

            }
            // 38;5;n (256-color fg) and 38;2;r;g;b (truecolor fg).
            if ($code === 38 || $code === 48) {
                $kind = $code === 38 ? 'foreground' : 'background';
                $sub  = (int) ($codes[$i + 1] ?? 0);
                if ($sub === 5) {
                    if (!isset($codes[$i + 2])) {
                        $parts[] = "$kind truncated 256-color";
                        $i += 1; // Only the sub (5) was present.
                    } else {
                        $parts[] = sprintf('%s 256-color %d', $kind, (int) $codes[$i + 2]);
                        $i += 2;
                    }
                } elseif ($sub === 2) {
                    if (!isset($codes[$i + 2]) || !isset($codes[$i + 3]) || !isset($codes[$i + 4])) {
                        $parts[] = "$kind truncated truecolor";
                        // Advance past only the params that were present.
                        $present = 1; // the '2' sub-param itself.
                        if (isset($codes[$i + 2])) { $present++; }
                        if (isset($codes[$i + 3])) { $present++; }
                        if (isset($codes[$i + 4])) { $present++; }
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

    private static function describeTilde(string $params): string
    {
        return match ($params) {
            '1', '7'  => 'Home',
            '4', '8'  => 'End',
            '3'       => 'Delete',
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
            7    => 'auto wrap',
            12   => 'cursor blink',
            25   => 'cursor visibility',
            47   => 'alternate screen (legacy)',
            1000 => 'mouse press/release',
            1002 => 'mouse cell motion',
            1003 => 'mouse all motion',
            1004 => 'focus reporting',
            1006 => 'mouse SGR encoding',
            1015 => 'mouse urxvt encoding',
            1047 => 'alternate screen (no save)',
            1048 => 'save/restore cursor',
            1049 => 'alternate screen',
            2004 => 'bracketed paste',
            2026 => 'synchronized output',
            2027 => 'unicode (grapheme cluster) mode',
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
                '8'       => 'hyperlink ' . $safe,
                '9'       => str_starts_with($m[2], '4;')
                                ? 'progress ' . self::sanitizeLabelBytes(substr($m[2], 2))
                                : 'iTerm2 ' . $safe,
                '10'      => 'set foreground colour ' . $safe,
                '11'      => 'set background colour ' . $safe,
                '12'      => 'set cursor colour ' . $safe,
                '52'      => 'clipboard ' . $safe,
                '110'     => 'reset foreground colour',
                '111'     => 'reset background colour',
                '112'     => 'reset cursor colour',
                default   => "OSC $payload",
            };
        }
        if (in_array($payload, ['110', '111', '112'], true)) {
            return match ($payload) {
                '110' => 'reset foreground colour',
                '111' => 'reset background colour',
                '112' => 'reset cursor colour',
            };
        }
        return "OSC $payload";
    }

    /** Decode DCS payloads — XTVERSION reply, DECRQSS, DECRPSS, sixel. */
    public static function describeDcs(string $payload, int $final = 0): string
    {
        // XTVERSION reply: DCS P >| version ST
        // candy-ansi parser interprets: '>' as intermediate, '|' as final, 'version' as data
        // So payload = '>version' and final = '|'
        if (chr($final) === '|' && str_starts_with($payload, '>')) {
            return 'terminal version (XTVERSION reply): ' . substr($payload, 1);
        }
        // For StreamingInspector (old byte-loop) or when final is not available,
        // the payload may contain the full sequence including intermediate+final
        if (str_starts_with($payload, '>|')) {
            return 'terminal version (XTVERSION reply): ' . substr($payload, 2);
        }
        // sixel: DCS P q data ST
        // The 'q' is consumed as final byte, data starts directly with sixel pixels.
        // NOTE: streaming sixel (Step 9) currently calls describeDcs($payload) with
        // no $final — after Step 9 $final will be threaded through, restoring sixel
        // detection for streaming.  The str_contains 'sixel' check was dropped as
        // unreliable (real sixel data never contains the literal ASCII "sixel").
        if ($final === ord('q')) {
            return 'sixel image (' . strlen($payload) . ' bytes)';
        }
        // DECRPSS reply: candy-ansi parses '1$r0$p' as:
        // - final='r' (first final byte in 0x40-0x7E range), data='0$p'
        // - payload='$$1;0' from intermediate+prefix+params
        // Produce semantic: 'DECRPSS reply 1$r0$p'
        if (chr($final) === 'r' && str_starts_with($payload, '$$')) {
            $params = explode(';', substr($payload, 2));
            if (count($params) >= 2) {
                return 'DECRPSS reply ' . $params[0] . '$r' . $params[1] . '$p';
            }
        }
        // Unknown DCS: reconstruct full sequence including final byte
        if ($final !== 0) {
            return 'DCS ' . chr($final) . $payload;
        }
        return 'DCS ' . $payload;
    }

    /** Decode APC payloads — CandyZone markers, kitty graphics. */
    public static function describeApc(string $payload): string
    {
        if (str_starts_with($payload, 'candyzone:')) {
            return 'CandyZone marker ' . self::sanitizeLabelBytes(substr($payload, strlen('candyzone:')));
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
            'c' => 'reset to initial state',
            default => 'ESC ' . $byte,
        };
    }

    private static function underlineStyleName(int $n): string
    {
        return match ($n) {
            1 => 'underline single',
            2 => 'underline double',
            3 => 'underline curly',
            4 => 'underline dotted',
            5 => 'underline dashed',
            default => 'underline style ' . $n,
        };
    }

    /**
     * Replace C0 control bytes in a label interpolation with visible tokens.
     *
     * Prevents an embedded ESC in a captured OSC/APC payload from re-arming
     * a sequence when the report is rendered to a live terminal.
     *
     * @param string $s Raw payload string interpolated into a human-readable label.
     */
    private static function sanitizeLabelBytes(string $s): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
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
                default => sprintf('\\x%02X', ord($m[0])),
            },
            $s,
        );
    }
}

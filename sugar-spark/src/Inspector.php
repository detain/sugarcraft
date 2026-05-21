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
        $out = [];
        $len = strlen($input);
        $i   = 0;
        $textBuf = '';

        $flushText = static function () use (&$textBuf, &$out): void {
            if ($textBuf !== '') {
                $out[]   = new TextSegment($textBuf);
                $textBuf = '';
            }
        };

        while ($i < $len) {
            $b = $input[$i];
            if ($b !== "\x1b") {
                $byte = ord($b);
                // C0 control codes 0x00-0x1F (except ESC which is handled separately).
                if ($byte <= 0x1F) {
                    $flushText();
                    $out[] = new SequenceSegment($b, 'C0 ' . C0C1::c0Name($byte));
                    $i++;
                    continue;
                }
                $textBuf .= $b;
                $i++;
                continue;
            }

            // Bare ESC at end of input — flush as a "lone ESC" segment.
            $next = $input[$i + 1] ?? null;
            if ($next === null) {
                $flushText();
                $out[] = new SequenceSegment("\x1b", 'ESC');
                $i++;
                continue;
            }

            if ($next === '[') {
                // CSI: ESC [ params final
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($input[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $bytes  = substr($input, $i, $j - $i);
                $params = substr($input, $i + 2, $j - $i - 3);
                $final  = substr($bytes, -1);
                $flushText();
                $out[] = new SequenceSegment($bytes, self::describeCsi($params, $final));
                $i = $j;
                continue;
            }

            if ($next === ']') {
                // OSC: ESC ] payload (BEL | ESC \)
                $j = $i + 2;
                while ($j < $len) {
                    if ($input[$j] === "\x07") { $j++; break; }
                    if ($input[$j] === "\x1b" && ($input[$j + 1] ?? '') === '\\') {
                        $j += 2; break;
                    }
                    $j++;
                }
                $bytes   = substr($input, $i, $j - $i);
                $payload = substr($bytes, 2, -1);
                $payload = rtrim($payload, "\x1b");
                $flushText();
                $out[] = new SequenceSegment($bytes, self::describeOsc($payload));
                $i = $j;
                continue;
            }

            if ($next === 'O') {
                // SS3: ESC O <byte>
                if ($i + 2 < $len) {
                    $bytes = substr($input, $i, 3);
                    $flushText();
                    $out[] = new SequenceSegment($bytes, self::describeSs3($input[$i + 2]));
                    $i += 3;
                    continue;
                }
            }

            // DCS: ESC P payload (BEL | ESC \). Used by XTVERSION,
            // DECRPM, DECRPSS, sixel, and other "device" replies.
            if ($next === 'P') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($input[$j] === "\x07") { $j++; break; }
                    if ($input[$j] === "\x1b" && ($input[$j + 1] ?? '') === '\\') {
                        $j += 2; break;
                    }
                    $j++;
                }
                $bytes   = substr($input, $i, $j - $i);
                $payload = substr($bytes, 2, -2); // strip ESC P ... ESC \
                $flushText();
                $out[] = new SequenceSegment($bytes, self::describeDcs($payload));
                $i = $j;
                continue;
            }

            // APC: ESC _ payload ESC \ — CandyZone markers, kitty
            // graphics, and other custom application program commands.
            if ($next === '_') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($input[$j] === "\x07") { $j++; break; }
                    if ($input[$j] === "\x1b" && ($input[$j + 1] ?? '') === '\\') {
                        $j += 2; break;
                    }
                    $j++;
                }
                $bytes   = substr($input, $i, $j - $i);
                $payload = substr($bytes, 2, -2);
                $flushText();
                $out[] = new SequenceSegment($bytes, self::describeApc($payload));
                $i = $j;
                continue;
            }

            // Two-byte ESC <c> (e.g. ESC 7 = save cursor).
            // C1 control codes are 0x80-0x9F. In 7-bit form they are ESC + chr(0x40 + code).
            // e.g. C1 0x98 (SOS) = ESC + chr(0x58) = "ESC X".
            // NOTE: This is ambiguous with standard 7-bit escapes that use the same bytes
            // (e.g. ESC D = both 7-bit index AND 7-bit representation of C1 0x84).
            // We check for ord >= 0x80 to avoid colliding with standard escapes.
            // For raw 8-bit C1 (when the terminal sends actual 8-bit bytes), the C0
            // handler catches chr(0x80-0x9F) as control codes.
            $afterEscByte = ord($input[$i + 1]);
            if ($afterEscByte >= 0x80) {
                // C1 in 8-bit form: byte after ESC is raw C1 code 0x80-0x9F.
                $bytes = substr($input, $i, 2);
                $flushText();
                $out[] = new SequenceSegment($bytes, 'C1 ' . C0C1::c1Name($afterEscByte));
                $i += 2;
                continue;
            }
            $bytes = substr($input, $i, 2);
            $flushText();
            $out[] = new SequenceSegment($bytes, self::describeEsc($next));
            $i += 2;
        }

        $flushText();
        return $out;
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
     * Render parsed segments as a machine-readable JSON array.
     *
     * Each entry: {"type": "text"|"sequence", "content": "...", "description": "..."}
     *
     * @return list<array{type: string, content: string, description: string}>
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
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
            'P' => 'F1',
            'Q' => 'F2',
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
        for ($i = 0; $i < count($codes); $i++) {
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
                if (isset($codes[$i + 1]) && preg_match('/^4:(\d+)$/', $codes[$i + 1]) === 1) {
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
                    $parts[] = sprintf('%s 256-color %d', $kind, (int) ($codes[$i + 2] ?? 0));
                    $i += 2;
                } elseif ($sub === 2) {
                    $parts[] = sprintf('%s rgb(%d,%d,%d)', $kind,
                        (int) ($codes[$i + 2] ?? 0),
                        (int) ($codes[$i + 3] ?? 0),
                        (int) ($codes[$i + 4] ?? 0));
                    $i += 4;
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
            return match ($m[1]) {
                '0', '2'  => 'set window title to "' . $m[2] . '"',
                '1'       => 'set icon name to "' . $m[2] . '"',
                '4'       => 'palette ' . $m[2],
                '7'       => 'cwd ' . $m[2],
                '8'       => 'hyperlink ' . $m[2],
                '9'       => str_starts_with($m[2], '4;')
                                ? 'progress ' . substr($m[2], 2)
                                : 'iTerm2 ' . $m[2],
                '10'      => 'set foreground colour ' . $m[2],
                '11'      => 'set background colour ' . $m[2],
                '12'      => 'set cursor colour ' . $m[2],
                '52'      => 'clipboard ' . $m[2],
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
    public static function describeDcs(string $payload): string
    {
        if (str_starts_with($payload, '>|')) {
            return 'terminal version (XTVERSION reply): ' . substr($payload, 2);
        }
        if (str_starts_with($payload, '1$r') || str_starts_with($payload, '0$r')) {
            return 'DECRPSS reply ' . $payload;
        }
        if (str_starts_with($payload, 'q')) {
            return 'sixel image (' . strlen($payload) . ' bytes)';
        }
        return 'DCS ' . $payload;
    }

    /** Decode APC payloads — CandyZone markers, kitty graphics. */
    public static function describeApc(string $payload): string
    {
        if (str_starts_with($payload, 'candyzone:')) {
            return 'CandyZone marker ' . substr($payload, strlen('candyzone:'));
        }
        if (str_starts_with($payload, 'G')) {
            return 'kitty graphics (' . strlen($payload) . ' bytes)';
        }
        return 'APC ' . $payload;
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
}

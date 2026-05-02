<?php

declare(strict_types=1);

namespace CandyCore\Spark;

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

            // Two-byte ESC <c> (e.g. ESC 7 = save cursor).
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

    private static function describeCsi(string $params, string $final): string
    {
        // Bracketed paste, focus reporting, mouse modes — DEC private.
        if (isset($params[0]) && $params[0] === '?' && ($final === 'h' || $final === 'l')) {
            $on    = $final === 'h';
            $codes = explode(';', substr($params, 1));
            $names = array_map(static fn(string $c) => self::decPrivateName((int) $c), $codes);
            return ($on ? 'enable ' : 'disable ') . implode(', ', $names);
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
            'R' => 'F3',
            'S' => 'F4',
            's' => 'save cursor',
            'u' => 'restore cursor',
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
        for ($i = 0; $i < count($codes); $i++) {
            $code = (int) $codes[$i];
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
            25   => 'cursor visibility',
            1000 => 'mouse press/release',
            1002 => 'mouse cell motion',
            1003 => 'mouse all motion',
            1004 => 'focus reporting',
            1006 => 'mouse SGR encoding',
            1049 => 'alternate screen',
            2004 => 'bracketed paste',
            default => "DEC ?$code",
        };
    }

    private static function describeOsc(string $payload): string
    {
        if (preg_match('/^(\d+);(.*)$/s', $payload, $m) === 1) {
            return match ($m[1]) {
                '0', '2'  => 'set window title to "' . $m[2] . '"',
                '1'       => 'set icon name to "' . $m[2] . '"',
                '7'       => 'cwd ' . $m[2],
                '8'       => 'hyperlink ' . $m[2],
                '52'      => 'clipboard ' . $m[2],
                default   => "OSC $payload",
            };
        }
        return "OSC $payload";
    }

    private static function describeSs3(string $final): string
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

    private static function describeEsc(string $byte): string
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
}

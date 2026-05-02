<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\BlurMsg;
use CandyCore\Core\Msg\FocusMsg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\MouseMsg;

/**
 * Stateful byte-stream parser. Feed it raw bytes via {@see parse()};
 * it returns zero or more typed {@see Msg} values per call. Partial escape
 * sequences are buffered across calls so split reads (common with stdin)
 * still produce correct keys.
 *
 * Phase-3 round 1 covers: printable ASCII, control characters
 * (Ctrl+A‒Z, Tab, Enter, Backspace, Escape, Space), Alt-prefixed keys
 * (ESC + char), and the 4 arrow CSIs. Function keys, mouse, and the
 * Kitty keyboard protocol arrive in a follow-up.
 */
final class InputReader
{
    private string $buf = '';

    /**
     * @return list<Msg>
     */
    public function parse(string $bytes): array
    {
        $this->buf .= $bytes;
        $msgs = [];
        $i = 0;
        $len = strlen($this->buf);

        while ($i < $len) {
            $b = $this->buf[$i];
            $code = ord($b);

            // ESC: maybe a CSI sequence, an alt-prefixed key, or a bare Escape.
            if ($code === 0x1b) {
                $remain = $len - $i;
                if ($remain === 1) {
                    // Could be the start of a longer sequence still arriving.
                    // Hold onto it for the next parse() call.
                    break;
                }
                $next = $this->buf[$i + 1];
                if ($next === '[') {
                    // CSI: ESC [ params final
                    if ($remain < 3) break;
                    $j = $i + 2;
                    while ($j < $len) {
                        $c = ord($this->buf[$j]);
                        $j++;
                        if ($c >= 0x40 && $c <= 0x7e) {
                            // final byte
                            $msg = $this->decodeCsi(substr($this->buf, $i + 2, $j - $i - 3), chr($c));
                            if ($msg !== null) $msgs[] = $msg;
                            $i = $j;
                            continue 2;
                        }
                    }
                    // Incomplete CSI; wait for more bytes.
                    break;
                }
                // Alt-prefixed key (ESC + printable byte).
                $code2 = ord($next);
                if ($code2 >= 0x20 && $code2 < 0x7f) {
                    $msgs[] = $this->decodeChar($code2, alt: true);
                    $i += 2;
                    continue;
                }
                // Bare Escape — consume just the one byte.
                $msgs[] = new KeyMsg(KeyType::Escape);
                $i += 1;
                continue;
            }

            // Plain control / printable byte.
            $msgs[] = $this->decodeChar($code);
            $i += 1;
        }

        $this->buf = substr($this->buf, $i);
        return $msgs;
    }

    public function flushPending(): ?Msg
    {
        if ($this->buf === '') {
            return null;
        }
        if (ord($this->buf[0]) === 0x1b && strlen($this->buf) === 1) {
            $this->buf = '';
            return new KeyMsg(KeyType::Escape);
        }
        return null;
    }

    private function decodeChar(int $code, bool $alt = false): KeyMsg
    {
        return match (true) {
            $code === 0x09 => new KeyMsg(KeyType::Tab,       alt: $alt),
            $code === 0x0d, $code === 0x0a
                          => new KeyMsg(KeyType::Enter,     alt: $alt),
            $code === 0x7f, $code === 0x08
                          => new KeyMsg(KeyType::Backspace, alt: $alt),
            $code === 0x20 => new KeyMsg(KeyType::Space,    rune: ' ', alt: $alt),
            $code === 0x1b => new KeyMsg(KeyType::Escape,   alt: $alt),
            $code >= 1 && $code <= 26
                          => new KeyMsg(KeyType::Char, rune: chr(0x60 + $code), alt: $alt, ctrl: true),
            $code >= 0x20 && $code < 0x7f
                          => new KeyMsg(KeyType::Char, rune: chr($code), alt: $alt),
            default        => new KeyMsg(KeyType::Char, rune: chr($code), alt: $alt),
        };
    }

    private function decodeCsi(string $params, string $final): ?Msg
    {
        // Focus reporting (CSI ?1004h): ESC[I → focus in, ESC[O → focus out.
        if ($params === '') {
            if ($final === 'I') return new FocusMsg();
            if ($final === 'O') return new BlurMsg();
        }

        // SGR-encoded mouse (CSI ?1006h): ESC[< b ; x ; y M|m
        if (($final === 'M' || $final === 'm') && isset($params[0]) && $params[0] === '<') {
            return $this->decodeSgrMouse(substr($params, 1), $final === 'M');
        }

        return match ($final) {
            'A' => new KeyMsg(KeyType::Up),
            'B' => new KeyMsg(KeyType::Down),
            'C' => new KeyMsg(KeyType::Right),
            'D' => new KeyMsg(KeyType::Left),
            'H' => new KeyMsg(KeyType::Home),
            'F' => new KeyMsg(KeyType::End),
            '~' => match ($params) {
                '1', '7' => new KeyMsg(KeyType::Home),
                '4', '8' => new KeyMsg(KeyType::End),
                '3'      => new KeyMsg(KeyType::Delete),
                '5'      => new KeyMsg(KeyType::PageUp),
                '6'      => new KeyMsg(KeyType::PageDown),
                default  => null,
            },
            default => null,
        };
    }

    private function decodeSgrMouse(string $params, bool $press): ?MouseMsg
    {
        $parts = explode(';', $params);
        if (count($parts) !== 3) {
            return null;
        }
        $b = (int) $parts[0];
        $x = (int) $parts[1];
        $y = (int) $parts[2];

        $shift = ($b & 0x04) !== 0;
        $alt   = ($b & 0x08) !== 0;
        $ctrl  = ($b & 0x10) !== 0;

        $isMotion = ($b & 0x20) !== 0;
        $isWheel  = ($b & 0x40) !== 0;
        $isExtra  = ($b & 0x80) !== 0;
        $btnBits  = $b & 0x03;

        if ($isWheel) {
            $button = $btnBits === 0 ? MouseButton::WheelUp : MouseButton::WheelDown;
            $action = MouseAction::Press;
        } elseif ($isExtra) {
            $button = $btnBits === 0 ? MouseButton::Backward : MouseButton::Forward;
            $action = $isMotion ? MouseAction::Motion : ($press ? MouseAction::Press : MouseAction::Release);
        } else {
            $button = match ($btnBits) {
                0       => MouseButton::Left,
                1       => MouseButton::Middle,
                2       => MouseButton::Right,
                default => MouseButton::None,
            };
            $action = $isMotion
                ? MouseAction::Motion
                : ($press ? MouseAction::Press : MouseAction::Release);
        }

        return new MouseMsg($x, $y, $button, $action, $shift, $alt, $ctrl);
    }
}

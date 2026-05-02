<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\BackgroundColorMsg;
use CandyCore\Core\Msg\BlurMsg;
use CandyCore\Core\Msg\CursorPositionMsg;
use CandyCore\Core\Msg\FocusMsg;
use CandyCore\Core\Msg\ForegroundColorMsg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\MouseClickMsg;
use CandyCore\Core\Msg\MouseMotionMsg;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Core\Msg\MouseReleaseMsg;
use CandyCore\Core\Msg\MouseWheelMsg;
use CandyCore\Core\Msg\PasteMsg;

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
    private const PASTE_START = "\x1b[200~";
    private const PASTE_END   = "\x1b[201~";

    private string $buf = '';
    private bool $pasting = false;
    private string $pasteBuf = '';

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
            // Inside a bracketed-paste envelope: collect raw bytes until
            // the CSI 201~ end marker is seen.
            if ($this->pasting) {
                $end = strpos($this->buf, self::PASTE_END, $i);
                if ($end === false) {
                    $this->pasteBuf .= substr($this->buf, $i);
                    $i = $len;
                    break;
                }
                $this->pasteBuf .= substr($this->buf, $i, $end - $i);
                $msgs[]         = new PasteMsg($this->pasteBuf);
                $this->pasteBuf = '';
                $this->pasting  = false;
                $i = $end + strlen(self::PASTE_END);
                continue;
            }

            $b = $this->buf[$i];
            $code = ord($b);

            // ESC: maybe a CSI sequence, an SS3 sequence, an alt-prefixed
            // key, or a bare Escape.
            if ($code === 0x1b) {
                $remain = $len - $i;
                if ($remain === 1) {
                    break;
                }
                $next = $this->buf[$i + 1];
                if ($next === '[') {
                    if ($remain < 3) break;
                    $j = $i + 2;
                    while ($j < $len) {
                        $c = ord($this->buf[$j]);
                        $j++;
                        if ($c >= 0x40 && $c <= 0x7e) {
                            $params = substr($this->buf, $i + 2, $j - $i - 3);
                            $final  = chr($c);
                            // Bracketed paste: switch into paste mode and
                            // consume the start marker. Subsequent bytes
                            // (including the eventual 201~) are handled
                            // by the pasting branch above.
                            if ($final === '~' && $params === '200') {
                                $this->pasting  = true;
                                $this->pasteBuf = '';
                                $i = $j;
                                continue 2;
                            }
                            $msg = $this->decodeCsi($params, $final);
                            if ($msg !== null) $msgs[] = $msg;
                            $i = $j;
                            continue 2;
                        }
                    }
                    break;
                }
                if ($next === 'O') {
                    // SS3: ESC O <single byte>. Used for F1-F4 on most
                    // terminals (xterm sends "ESC O P" for F1, etc.).
                    if ($remain < 3) break;
                    $msg = $this->decodeSs3($this->buf[$i + 2]);
                    if ($msg !== null) $msgs[] = $msg;
                    $i += 3;
                    continue;
                }
                if ($next === ']') {
                    // OSC: ESC ] payload (ST | BEL). Used for terminal
                    // queries (OSC 10/11 colour replies, OSC 52 clipboard,
                    // OSC 0/2 title, etc.).
                    $end = $this->findOscEnd($i + 2, $len);
                    if ($end === null) {
                        // End marker not in the buffer yet — wait for
                        // more bytes.
                        break;
                    }
                    [$payload, $next_i] = $end;
                    $msg = $this->decodeOsc($payload);
                    if ($msg !== null) $msgs[] = $msg;
                    $i = $next_i;
                    continue;
                }
                // Alt-prefixed key.
                $code2 = ord($next);
                if ($code2 >= 0x20 && $code2 < 0x7f) {
                    $msgs[] = $this->decodeChar($code2, alt: true);
                    $i += 2;
                    continue;
                }
                $msgs[] = new KeyMsg(KeyType::Escape);
                $i += 1;
                continue;
            }

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

    /**
     * True when the parser is holding a single ESC byte awaiting
     * disambiguation. {@see \CandyCore\Core\Program} polls this so it can
     * promote the byte to a bare Escape after a short timeout.
     */
    public function hasPendingEscape(): bool
    {
        return $this->buf === "\x1b";
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

        // DSR cursor-position report (reply to CSI 6n): CSI <row> ; <col> R.
        // F3 also sends `CSI R` but with no params, so the digit prefix
        // tells the two apart.
        if ($final === 'R' && $params !== '' && preg_match('/^(\d+);(\d+)$/', $params, $m) === 1) {
            return new CursorPositionMsg((int) $m[1], (int) $m[2]);
        }

        return match ($final) {
            'A' => new KeyMsg(KeyType::Up),
            'B' => new KeyMsg(KeyType::Down),
            'C' => new KeyMsg(KeyType::Right),
            'D' => new KeyMsg(KeyType::Left),
            'H' => new KeyMsg(KeyType::Home),
            'F' => new KeyMsg(KeyType::End),
            'P' => new KeyMsg(KeyType::F1),
            'Q' => new KeyMsg(KeyType::F2),
            'R' => new KeyMsg(KeyType::F3),
            'S' => new KeyMsg(KeyType::F4),
            '~' => match ($params) {
                '1', '7' => new KeyMsg(KeyType::Home),
                '4', '8' => new KeyMsg(KeyType::End),
                '3'      => new KeyMsg(KeyType::Delete),
                '5'      => new KeyMsg(KeyType::PageUp),
                '6'      => new KeyMsg(KeyType::PageDown),
                '11'     => new KeyMsg(KeyType::F1),
                '12'     => new KeyMsg(KeyType::F2),
                '13'     => new KeyMsg(KeyType::F3),
                '14'     => new KeyMsg(KeyType::F4),
                '15'     => new KeyMsg(KeyType::F5),
                '17'     => new KeyMsg(KeyType::F6),
                '18'     => new KeyMsg(KeyType::F7),
                '19'     => new KeyMsg(KeyType::F8),
                '20'     => new KeyMsg(KeyType::F9),
                '21'     => new KeyMsg(KeyType::F10),
                '23'     => new KeyMsg(KeyType::F11),
                '24'     => new KeyMsg(KeyType::F12),
                default  => null,
            },
            default => null,
        };
    }

    /**
     * Locate an OSC terminator starting at byte $start in the buffer.
     * Returns `[payload, next_i]` or null if the terminator hasn't
     * arrived yet. OSC strings end with either `\x07` (BEL) or the
     * two-byte ST sequence `ESC \`.
     *
     * @return array{0:string,1:int}|null
     */
    private function findOscEnd(int $start, int $len): ?array
    {
        for ($k = $start; $k < $len; $k++) {
            $c = $this->buf[$k];
            if ($c === self::BEL) {
                return [substr($this->buf, $start, $k - $start), $k + 1];
            }
            if ($c === "\x1b" && ($this->buf[$k + 1] ?? '') === '\\') {
                return [substr($this->buf, $start, $k - $start), $k + 2];
            }
        }
        return null;
    }

    private function decodeOsc(string $payload): ?Msg
    {
        // OSC 10 / 11: foreground / background colour reports. Format
        // is `<num>;rgb:RRRR/GGGG/BBBB` — each channel is 1-4 hex
        // digits and we squash to 8-bit.
        if (preg_match('/^(10|11);rgb:([0-9a-fA-F]{1,4})\/([0-9a-fA-F]{1,4})\/([0-9a-fA-F]{1,4})$/', $payload, $m) === 1) {
            $r = self::scaleHex($m[2]);
            $g = self::scaleHex($m[3]);
            $b = self::scaleHex($m[4]);
            return $m[1] === '10'
                ? new ForegroundColorMsg($r, $g, $b)
                : new BackgroundColorMsg($r, $g, $b);
        }
        return null;
    }

    /**
     * Convert a 1-4 digit hex string into an 8-bit channel value. The
     * terminal reports channels at the resolution it has available
     * (xterm uses 4 hex digits = 16 bits per channel) so we scale down
     * proportionally to the [0, 255] range we expose.
     */
    private static function scaleHex(string $hex): int
    {
        $maxFor = (1 << (4 * strlen($hex))) - 1;
        $value = (int) hexdec($hex);
        return (int) round(($value / $maxFor) * 255);
    }

    private const BEL = "\x07";

    /**
     * Decode an SS3 final byte (the byte after `ESC O`). Most terminals
     * use SS3 for F1-F4 — F5+ generally come back as CSI ~ sequences.
     */
    private function decodeSs3(string $final): ?Msg
    {
        return match ($final) {
            'P' => new KeyMsg(KeyType::F1),
            'Q' => new KeyMsg(KeyType::F2),
            'R' => new KeyMsg(KeyType::F3),
            'S' => new KeyMsg(KeyType::F4),
            'A' => new KeyMsg(KeyType::Up),
            'B' => new KeyMsg(KeyType::Down),
            'C' => new KeyMsg(KeyType::Right),
            'D' => new KeyMsg(KeyType::Left),
            'H' => new KeyMsg(KeyType::Home),
            'F' => new KeyMsg(KeyType::End),
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

        // Pick the v2-style concrete subclass so callers can pattern-
        // match on event kind via instanceof. All four subclasses still
        // satisfy `instanceof MouseMsg`, so existing handlers keep
        // working unchanged.
        $class = match (true) {
            $isWheel                          => MouseWheelMsg::class,
            $action === MouseAction::Motion   => MouseMotionMsg::class,
            $action === MouseAction::Release  => MouseReleaseMsg::class,
            default                           => MouseClickMsg::class,
        };
        return new $class($x, $y, $button, $action, $shift, $alt, $ctrl);
    }
}

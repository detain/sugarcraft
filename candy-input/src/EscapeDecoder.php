<?php

declare(strict_types=1);

namespace SugarCraft\Input;

use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\ResizeEvent;

/**
 * Terminal escape sequence decoder.
 *
 * Consumes raw bytes via decode() and emits typed Event objects.
 * Handles partial sequences across calls — state is reentrant per instance.
 *
 * Supported sequences:
 *  - Plain ASCII + control codes (Backspace, Tab, Enter, Esc, Ctrl+letter)
 *  - Legacy CSI sequences (arrows, F1-F12, Home/End/PgUp/PgDn, Insert, Delete)
 *  - Kitty keyboard protocol (CSI ?u with disambiguation flags)
 *  - SGR 1006 mouse (CSI < button ; x ; y M|m)
 *  - Focus events (CSI I / CSI O)
 *  - Bracketed paste (CSI 200 ~ ... CSI 201 ~)
 *
 * @see Mirrors charmbracelet/bubbletea (input handling).
 */
final class EscapeDecoder
{
    // Bracketed paste sentinel start and end
    private const PASTE_START = "\x1b[200~";
    private const PASTE_END   = "\x1b[201~";

    /** @var list<Event> */
    private array $buffer = [];

    /** Remaining bytes after last decode() that couldn't form a complete sequence */
    private string $remainder = '';

    /** Paste accumulation buffer */
    private string $pasteBuffer = '';

    /** Whether we are currently inside a bracketed paste */
    private bool $inPaste = false;

    /**
     * Decode a byte buffer into 0+ Events.
     *
     * Incomplete sequences are buffered and decoded on the next call
     * when more bytes arrive. Use remainder() to get unconsumed bytes.
     *
     * @param string $bytes Raw bytes from the terminal
     * @return list<Event>
     */
    public function decode(string $bytes): array
    {
        if ($bytes === '' && $this->remainder === '' && !$this->inPaste) {
            return [];
        }

        // Prepend any leftover bytes from previous incomplete decode
        $stream = $this->remainder . $bytes;
        $this->remainder = '';

        // Handle in-progress bracketed paste
        if ($this->inPaste) {
            return $this->handlePasteStream($stream);
        }

        // Check for paste start anywhere in stream
        $pasteStartPos = strpos($stream, self::PASTE_START);
        if ($pasteStartPos !== false) {
            // Decode any bytes before the paste start normally
            $prefix = substr($stream, 0, $pasteStartPos);
            $prefixEvents = $prefix !== '' ? $this->decodeClean($prefix) : [];

            $afterStart = substr($stream, $pasteStartPos + strlen(self::PASTE_START));
            $this->pasteBuffer = '';
            $this->inPaste = true;

            return $this->finishPaste($prefixEvents, $afterStart);
        }

        return $this->decodeClean($stream);
    }

    /**
     * Core decode logic without paste handling.
     *
     * @return list<Event>
     */
    private function decodeClean(string $stream): array
    {
        $events = [];

        while ($stream !== '') {
            $byte = $stream[0];
            $ord = ord($byte);

            // Escape character
            if ($ord === 0x1b) {
                $result = $this->handleEscape($stream);
                if ($result['events'] !== []) {
                    $events = array_merge($events, $result['events']);
                    $stream = $result['remaining'];
                    continue;
                }
                // No events but have remaining bytes — consume what we can
                if ($result['remaining'] !== '' && $result['remaining'] !== $stream) {
                    // Partial progress: some bytes were consumed, continue with remainder
                    $stream = $result['remaining'];
                    continue;
                }
                // Incomplete escape sequence — buffer and stop
                $this->remainder = $stream;
                return $events;
            }

            // Control characters
            if ($ord <= 0x1f) {
                $events[] = $this->decodeControlChar($byte);
                $stream = substr($stream, 1);
                continue;
            }

            // DEL
            if ($ord === 0x7f) {
                $events[] = new KeyEvent('Backspace', KeyModifier::none(), "\x7f");
                $stream = substr($stream, 1);
                continue;
            }

            // Printable
            $events[] = new KeyEvent($byte, KeyModifier::none(), $byte);
            $stream = substr($stream, 1);
        }

        return $events;
    }

    /**
     * Handle escape character at start of stream.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleEscape(string $stream): array
    {
        if (strlen($stream) === 1) {
            // Lone ESC
            return ['events' => [new KeyEvent('Escape', KeyModifier::none(), "\x1b")], 'remaining' => ''];
        }

        $next = $stream[1];
        $nextOrd = ord($next);

        // ESC [ — CSI sequence
        if ($nextOrd === 0x5b) {
            return $this->handleCSI(substr($stream, 2));
        }

        // ESC ESC — Alt+Escape
        if ($nextOrd === 0x1b) {
            return [
                'events' => [new KeyEvent('Escape', KeyModifier::alt(), "\x1b\x1b")],
                'remaining' => substr($stream, 2),
            ];
        }

        // ESC <non-[> — Alt+key
        return [
            'events' => [new KeyEvent($this->mapChar($next), KeyModifier::alt(), "\x1b" . $next)],
            'remaining' => substr($stream, 2),
        ];
    }

    /**
     * Handle a CSI (ESC [) sequence.
     *
     * @param string $afterCsi Bytes after "ESC ["
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleCSI(string $afterCsi): array
    {
        if ($afterCsi === '') {
            // Incomplete CSI
            return ['events' => [], 'remaining' => "\x1b["];
        }

        // SGR 1006 mouse: CSI < Pb ; x ; y M|m
        if ($afterCsi[0] === '<') {
            return $this->handleSgrMouse(substr($afterCsi, 1));
        }

        // Focus events: CSI I (gained) or CSI O (lost)
        if ($afterCsi === 'I') {
            return ['events' => [new FocusEvent(true)], 'remaining' => ''];
        }
        if ($afterCsi === 'O') {
            return ['events' => [new FocusEvent(false)], 'remaining' => ''];
        }

        // Kitty keyboard protocol: CSI ? Pm ; Ps u
        if (str_starts_with($afterCsi, '?')) {
            $result = $this->handleKitty(substr($afterCsi, 1));
            if ($result['events'] !== []) {
                return $result;
            }
            // Incomplete Kitty sequence
            return ['events' => [], 'remaining' => "\x1b[" . $afterCsi];
        }

        // Standard CSI key sequences
        return $this->handleCsiKey($afterCsi);
    }

    /**
     * Handle SGR 1006 mouse sequence.
     *
     * @param string $afterLt Bytes after the "<"
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleSgrMouse(string $afterLt): array
    {
        // Find the final M or m
        $endPos = strpos($afterLt, 'M');
        $isReleaseChar = false;
        if ($endPos === false) {
            $endPos = strpos($afterLt, 'm');
            if ($endPos === false) {
                // Incomplete
                return ['events' => [], 'remaining' => "\x1b[<" . $afterLt];
            }
            $isReleaseChar = true;
        }

        $params = substr($afterLt, 0, $endPos);
        $remaining = substr($afterLt, $endPos + 1);

        $parts = explode(';', $params);
        if (count($parts) !== 3) {
            return ['events' => [], 'remaining' => "\x1b[<" . $afterLt];
        }

        [$btnRaw, $x, $y] = $parts;
        $button = (int) $btnRaw;

        // Scroll events: button 96 = scroll up, 97 = scroll down
        if ($button === 96) {
            return [
                'events' => [MouseEvent::scrollUp((int)$x, (int)$y, KeyModifier::none())],
                'remaining' => $remaining,
            ];
        }
        if ($button === 97) {
            return [
                'events' => [MouseEvent::scrollDown((int)$x, (int)$y, KeyModifier::none())],
                'remaining' => $remaining,
            ];
        }

        // Extract modifiers from SGR button field (bit 2=Shift, bit 3=Alt, bit 4=Ctrl)
        // These are added to the base button value, not separate bits to shift
        $modifierBits = 0;
        if ($button & 4)  { $modifierBits |= 1; } // Shift → SGR bit 0
        if ($button & 8)  { $modifierBits |= 2; } // Alt   → SGR bit 1
        if ($button & 16) { $modifierBits |= 4; } // Ctrl  → SGR bit 2
        // Base button is bits 0-1 of the button field
        $button = $button & 3;

        $modifiers = KeyModifier::fromSgrMouse($modifierBits);
        $isRelease = $isReleaseChar || $button === 3;
        $action = $isRelease ? MouseEvent::ACTION_RELEASE : MouseEvent::ACTION_PRESS;

        // Button number is already the base (0-2) after modifier extraction

        return [
            'events' => [new MouseEvent((int)$x, (int)$y, $button, $action, $modifiers)],
            'remaining' => $remaining,
        ];
    }

    /**
     * Handle a Kitty keyboard protocol sequence.
     *
     * @param string $afterQuestion Bytes after "?"
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleKitty(string $afterQuestion): array
    {
        // Format: Pm;Psu (key_code;modifiersu)
        if (!preg_match('/^(\d+);(\d+)u/', $afterQuestion, $matches)) {
            // Incomplete
            return ['events' => [], 'remaining' => ''];
        }

        $keyCode = (int) $matches[1];
        $modRaw  = (int) $matches[2];
        $remaining = substr($afterQuestion, strlen($matches[0]));

        // Key release: modifiers OR 0x20
        $isRelease = ($modRaw & 0x20) !== 0;
        $modClean = $modRaw & 0x1f; // strip release bit
        $modifiers = KeyModifier::fromKittyInt($modClean);

        $keyName = $this->kittyKeyCodeToName($keyCode);
        if ($keyName === null) {
            return ['events' => [], 'remaining' => ''];
        }

        $fullKey = $isRelease ? 'Release' . ucfirst($keyName) : $keyName;
        $raw = "\x1b[?" . $afterQuestion;

        return [
            'events' => [new KeyEvent($fullKey, $modifiers, $raw)],
            'remaining' => $remaining,
        ];
    }

    /**
     * Handle a standard CSI key sequence.
     *
     * @param string $csi Bytes after "ESC ["
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleCsiKey(string $csi): array
    {
        if ($csi === '') {
            return ['events' => [], 'remaining' => "\x1b["];
        }

        // Arrow keys: CSI A/B/C/D (or SS3 O{A/B/C/D})
        $arrowMap = ['A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft'];
        if (isset($arrowMap[$csi[0]])) {
            return [
                'events' => [new KeyEvent($arrowMap[$csi[0]], KeyModifier::none(), "\x1b[" . $csi)],
                'remaining' => substr($csi, 1),
            ];
        }

        // SS3-style function keys: CSI OP/.../OS (some terminals use CSI O{P/Q/R/S})
        $ss3Map = ['P' => 'F1', 'Q' => 'F2', 'R' => 'F3', 'S' => 'F4'];
        // Also check if it starts with O and the second char is a function key
        if (isset($csi[1]) && isset($ss3Map[$csi[1]])) {
            return [
                'events' => [new KeyEvent($ss3Map[$csi[1]], KeyModifier::none(), "\x1b[" . $csi)],
                'remaining' => substr($csi, 2),
            ];
        }

        // Home / End: CSI H or CSI F
        if ($csi === 'H') return ['events' => [new KeyEvent('Home', KeyModifier::none(), "\x1b[H")], 'remaining' => ''];
        if ($csi === 'F') return ['events' => [new KeyEvent('End', KeyModifier::none(), "\x1b[F")], 'remaining' => ''];

        // Bracketed paste: CSI 200 ~ (start) and CSI 201 ~ (end) are handled
        // elsewhere as paste sentinel markers. They should not emit key events.
        // Return incomplete so the main decode loop can detect the paste marker.
        if ($csi === '200~' || $csi === '201~') {
            return ['events' => [], 'remaining' => "\x1b[" . $csi];
        }

        // Numbered function keys and special keys: 1~, 2~, 3~, etc.
        if (preg_match('/^(\d+)(~[HF]?|~)$/', $csi, $m)) {
            $num = (int) $m[1];
            $suffix = $m[2] ?? '';

            $specialKeys = [
                '1' => 'Home', '2' => 'Insert', '3' => 'Delete', '4' => 'End',
                '5' => 'PageUp', '6' => 'PageDown',
            ];

            // F1-F4 via 11~-14~ (some terminals) or SS3
            if (isset($specialKeys[(string)$num])) {
                $key = $specialKeys[(string)$num];
                return [
                    'events' => [new KeyEvent($key, KeyModifier::none(), "\x1b[" . $csi)],
                    'remaining' => '',
                ];
            }

            $fKeys = [
                '11' => 'F1', '12' => 'F2', '13' => 'F3', '14' => 'F4',
                '15' => 'F5', '17' => 'F6', '18' => 'F7', '19' => 'F8',
                '20' => 'F9', '21' => 'F10', '23' => 'F11', '24' => 'F12',
                '25' => 'F13', '26' => 'F14', '28' => 'F15', '29' => 'F16',
                '31' => 'F17', '32' => 'F18', '33' => 'F19', '34' => 'F20',
                '35' => 'F21', '36' => 'F22', '37' => 'F23', '38' => 'F24',
            ];

            if (isset($fKeys[(string)$num])) {
                return [
                    'events' => [new KeyEvent($fKeys[(string)$num], KeyModifier::none(), "\x1b[" . $csi)],
                    'remaining' => '',
                ];
            }
        }

        // CSI number without known suffix — it might be a partial numbered key sequence
        // e.g., "15" needs "~" to become "15~" (F5). Buffer it and wait for more.
        if (is_numeric($csi[0]) && strlen($csi) > 0) {
            if (is_numeric($csi[strlen($csi) - 1])) {
                // Ends with digit — could be partial numbered key, buffer
                return ['events' => [], 'remaining' => "\x1b[" . $csi];
            }
            // Non-numeric suffix — unknown complete CSI sequence.
            // Find the final byte: it's the last byte in the valid final range (0x40-0x7E)
            // that either has an intermediate byte before it OR is preceded by a digit
            // (indicating parameters followed by final).
            $csiLen = strlen($csi);
            $finalBytePos = -1;
            for ($i = $csiLen - 1; $i >= 0; $i--) {
                $ord = ord($csi[$i]);
                if ($ord >= 0x40 && $ord <= 0x7e) {
                    $finalBytePos = $i;
                    break;
                }
            }
            if ($finalBytePos === -1) {
                // No final byte found - might be incomplete, buffer
                return ['events' => [], 'remaining' => "\x1b[" . $csi];
            }
            // Check if there's a valid intermediate (0x20-0x2F) or parameter before final byte
            $beforeFinal = $finalBytePos - 1;
            if ($beforeFinal >= 0) {
                $beforeOrd = ord($csi[$beforeFinal]);
                $isIntermediate = $beforeOrd >= 0x20 && $beforeOrd <= 0x2f;
                $isDigitOrSemicolon = is_numeric($csi[$beforeFinal]) || $csi[$beforeFinal] === ';';
                // If the byte before final is NOT a valid intermediate or parameter,
                // the last byte might be trailing, not part of the CSI
                if (!$isIntermediate && !$isDigitOrSemicolon && $finalBytePos === $csiLen - 1) {
                    // Last byte is non-parameter, non-intermediate, and we're at the end
                    // This suggests it might be trailing. Re-scan excluding last byte.
                    $altFinalBytePos = -1;
                    for ($i = $finalBytePos - 1; $i >= 0; $i--) {
                        $ord = ord($csi[$i]);
                        if ($ord >= 0x40 && $ord <= 0x7e) {
                            $altFinalBytePos = $i;
                            break;
                        }
                    }
                    if ($altFinalBytePos !== -1) {
                        $finalBytePos = $altFinalBytePos;
                    }
                }
            }
            // There might be bytes after the final byte (trailing)
            $trailingStart = $finalBytePos + 1;
            if ($trailingStart < $csiLen) {
                $remaining = substr($csi, $trailingStart);
                return ['events' => [], 'remaining' => $remaining];
            }
            // Full CSI consumed, no trailing bytes
            return ['events' => [], 'remaining' => ''];
        }

        // Non-numeric CSI final byte we don't recognize — skip the first byte
        return [
            'events' => [],
            'remaining' => substr($csi, 1),
        ];
    }

    /**
     * Handle paste stream — check for paste end.
     *
     * @return list<Event>
     */
    private function handlePasteStream(string $stream): array
    {
        $pasteEndPos = strpos($stream, self::PASTE_END);
        if ($pasteEndPos === false) {
            // Not complete — accumulate
            $this->pasteBuffer .= $stream;
            if (strlen($this->pasteBuffer) > PasteEvent::MAX_SIZE * 2) {
                // Force-close on oversized paste
                $event = PasteEvent::truncate($this->pasteBuffer);
                $this->pasteBuffer = '';
                $this->inPaste = false;
                return [$event];
            }
            return [];
        }

        $pasteContent = $this->pasteBuffer . substr($stream, 0, $pasteEndPos);
        $afterEnd = substr($stream, $pasteEndPos + strlen(self::PASTE_END));

        $this->pasteBuffer = '';
        $this->inPaste = false;
        $this->remainder = $afterEnd;

        return [PasteEvent::truncate($pasteContent)];
    }

    /**
     * Finish paste — check if the afterStart bytes contain the paste end.
     *
     * @param list<Event> $prefixEvents
     * @param string $afterStart
     * @return list<Event>
     */
    private function finishPaste(array $prefixEvents, string $afterStart): array
    {
        $pasteEndPos = strpos($afterStart, self::PASTE_END);
        if ($pasteEndPos === false) {
            $this->pasteBuffer .= $afterStart;
            return $prefixEvents;
        }

        $pasteContent = $this->pasteBuffer . substr($afterStart, 0, $pasteEndPos);
        $afterEnd = substr($afterStart, $pasteEndPos + strlen(self::PASTE_END));

        $this->pasteBuffer = '';
        $this->inPaste = false;
        $this->remainder = $afterEnd;

        return array_merge($prefixEvents, [PasteEvent::truncate($pasteContent)]);
    }

    /**
     * Map a raw character byte to a key name for Alt-modified keys.
     */
    private function mapChar(string $byte): string
    {
        $ord = ord($byte);
        if ($ord >= 97 && $ord <= 122) {
            return chr($ord);
        }
        if ($ord >= 65 && $ord <= 90) {
            return chr($ord + 32);
        }
        return $byte;
    }

    /**
     * Decode a control character.
     */
    private function decodeControlChar(string $byte): KeyEvent
    {
        $ord = ord($byte);

        if ($ord === 0x09) return new KeyEvent('Tab', KeyModifier::none(), "\t");
        if ($ord === 0x0a || $ord === 0x0d) return new KeyEvent('Enter', KeyModifier::none(), $byte);
        if ($ord === 0x1b) return new KeyEvent('Escape', KeyModifier::none(), "\x1b");

        // Ctrl + letter (0x01-0x1a)
        if ($ord >= 0x01 && $ord <= 0x1a) {
            $letter = chr($ord + 0x60);
            return new KeyEvent($letter, KeyModifier::ctrl(), $byte);
        }

        return new KeyEvent($byte, KeyModifier::none(), $byte);
    }

    /**
     * Map a Kitty key code to a symbolic key name.
     *
     * @return string|null
     */
    private function kittyKeyCodeToName(int $code): string|null
    {
        // Tab, Enter, Escape, Backspace
        if ($code === 9)  return 'Tab';
        if ($code === 13) return 'Enter';
        if ($code === 27) return 'Escape';
        if ($code === 127) return 'Backspace';

        // Space
        if ($code === 32) return 'Space';

        // Letters
        if ($code >= 97 && $code <= 122) return chr($code);
        if ($code >= 65 && $code <= 90)  return chr($code + 32);

        // Arrow keys
        $arrowCodes = [
            57399 => 'ArrowUp',
            57400 => 'ArrowDown',
            57401 => 'ArrowRight',
            57402 => 'ArrowLeft',
            // Alternative codes
            126 => 'ArrowUp',
            127 => 'ArrowDown',
            128 => 'ArrowRight',
            129 => 'ArrowLeft',
        ];

        if (isset($arrowCodes[$code])) return $arrowCodes[$code];

        // Function keys
        $fKeys = [
            11 => 'F1', 12 => 'F2', 13 => 'F3', 14 => 'F4',
            15 => 'F5', 17 => 'F6', 18 => 'F7', 19 => 'F8',
            20 => 'F9', 21 => 'F10', 23 => 'F11', 24 => 'F12',
            25 => 'F13', 26 => 'F14', 28 => 'F15', 29 => 'F16',
            31 => 'F17', 32 => 'F18', 33 => 'F19', 34 => 'F20',
        ];

        if (isset($fKeys[$code])) return $fKeys[$code];

        // Special keys
        $special = [
            1 => 'Home', 2 => 'Insert', 3 => 'Delete', 4 => 'End',
            5 => 'PageUp', 6 => 'PageDown',
        ];

        if (isset($special[$code])) return $special[$code];

        return null;
    }

    /**
     * Get bytes that couldn't be consumed as part of a complete sequence.
     */
    public function remainder(): string
    {
        return $this->remainder;
    }

    /**
     * Clear the partial-sequence buffer.
     */
    public function reset(): void
    {
        $this->remainder = '';
        $this->pasteBuffer = '';
        $this->inPaste = false;
    }
}

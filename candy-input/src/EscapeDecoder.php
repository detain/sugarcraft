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

    /**
     * Upper bound on how many bytes of an INCOMPLETE sequence we will hold in
     * $remainder between decode() calls. A legitimate escape sequence is short
     * (a handful of bytes); a legitimate large paste flows through the separate
     * paste path, never $remainder. Anything longer is a malformed / hostile
     * stream (e.g. "ESC [" plus an endless run of parameter bytes with no final
     * byte) and is discarded rather than buffered without limit.
     */
    private const MAX_SEQUENCE_LENGTH = 128;

    /** @var list<Event> */
    private array $buffer = [];

    /** Remaining bytes after last decode() that couldn't form a complete sequence */
    private string $remainder = '';

    /** Paste accumulation buffer */
    private string $pasteBuffer = '';

    /** Whether we are currently inside a bracketed paste */
    private bool $inPaste = false;

    /** Protocol toggles gating mouse / kitty / focus / paste parsing. */
    private readonly EscapeDecoderOptions $options;

    /**
     * @param EscapeDecoderOptions|null $options Protocol filtering; null enables
     *   every protocol (the default), so a no-arg construction is unchanged.
     *   A disabled protocol's sequences are still structurally consumed (kept in
     *   byte-sync) but emit no event.
     */
    public function __construct(?EscapeDecoderOptions $options = null)
    {
        $this->options = $options ?? new EscapeDecoderOptions();
    }

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

        // Check for paste start anywhere in stream (only when paste parsing is
        // enabled; otherwise the markers fall through as ordinary — and ignored —
        // CSI sequences below).
        $pasteStartPos = $this->options->enablePaste ? strpos($stream, self::PASTE_START) : false;
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
     * Walks a fixed $stream with an integer offset $i rather than repeatedly
     * `substr()`-ing off the front. The common printable/control/DEL/UTF-8
     * cases advance $i in place — so a 160KB printable paste is O(n), not the
     * O(n²) that byte-by-byte substr slicing produced. The escape sub-handlers
     * still take/return strings unchanged, so we pay exactly one substr per
     * escape sequence (not per byte).
     *
     * @return list<Event>
     */
    private function decodeClean(string $stream): array
    {
        $events = [];
        $len = strlen($stream);
        $i = 0;

        while ($i < $len) {
            $byte = $stream[$i];
            $ord = ord($byte);

            // Escape character — hand the tail to the (unchanged) string-based
            // escape handlers. This is the ONE substr we allow per escape.
            if ($ord === 0x1b) {
                $tail = substr($stream, $i);
                $result = $this->handleEscape($tail);
                // Events produced OR partial progress made (remaining is a proper
                // suffix of the tail): advance past the consumed bytes and keep
                // walking the same fixed $stream. handleEscape's `remaining` is
                // always a suffix of `tail`, so (len-i) - strlen(remaining) is the
                // exact byte count consumed.
                if ($result['events'] !== [] || $result['remaining'] !== $tail) {
                    $events = array_merge($events, $result['events']);
                    $i += ($len - $i) - strlen($result['remaining']);
                    continue;
                }
                // Incomplete escape (no events, no progress): buffer the tail
                // (capped) for the next decode() call and stop.
                $this->bufferRemainder($tail);
                return $events;
            }

            // Control characters (ESC already handled above)
            if ($ord <= 0x1f) {
                $events[] = $this->decodeControlChar($byte);
                $i++;
                continue;
            }

            // DEL
            if ($ord === 0x7f) {
                $events[] = new KeyEvent('Backspace', KeyModifier::none(), "\x7f");
                $i++;
                continue;
            }

            // Printable ASCII
            if ($ord <= 0x7e) {
                $events[] = new KeyEvent($byte, KeyModifier::none(), $byte);
                $i++;
                continue;
            }

            // High byte (>= 0x80): a UTF-8 multibyte codepoint or an invalid byte.
            // Decode the WHOLE codepoint as a single event — never split a
            // multibyte char into per-byte KeyEvents (that corrupts it for every
            // downstream consumer that treats key/raw as a UTF-8 string).
            $seqLen = match (true) {
                $ord >= 0xc2 && $ord <= 0xdf => 2,
                $ord >= 0xe0 && $ord <= 0xef => 3,
                $ord >= 0xf0 && $ord <= 0xf4 => 4,
                // 0x80-0xBF (lone continuation) or 0xC0-0xC1/0xF5-0xFF (overlong
                // or out-of-range lead): not a valid lead byte.
                default => 0,
            };

            if ($seqLen === 0) {
                // Invalid lead — emit it alone and resync on the next byte so a
                // malformed stream can never hang the loop.
                $events[] = new KeyEvent($byte, KeyModifier::none(), $byte);
                $i++;
                continue;
            }

            if ($i + $seqLen > $len) {
                // Codepoint is split across the chunk boundary. If every byte we
                // DO have is a valid continuation byte, this is an incomplete-but-
                // valid tail: buffer it (a partial tail is <=3 bytes, so the cap
                // never trips) and stop — the next decode() call completes it.
                if ($this->allContinuationBytes($stream, $i + 1, $len)) {
                    $this->bufferRemainder(substr($stream, $i));
                    return $events;
                }
                // A present byte is already invalid — emit the lead alone, resync.
                $events[] = new KeyEvent($byte, KeyModifier::none(), $byte);
                $i++;
                continue;
            }

            if ($this->allContinuationBytes($stream, $i + 1, $i + $seqLen)) {
                // Full, well-formed codepoint present — emit as one event.
                $seq = substr($stream, $i, $seqLen);
                $events[] = new KeyEvent($seq, KeyModifier::none(), $seq);
                $i += $seqLen;
                continue;
            }

            // Malformed: a continuation byte is not 0x80-0xBF. Emit the lead byte
            // alone and resync on the next byte.
            $events[] = new KeyEvent($byte, KeyModifier::none(), $byte);
            $i++;
        }

        return $events;
    }

    /**
     * True if every byte in the half-open range [$from, $to) is a UTF-8
     * continuation byte (0x80-0xBF).
     */
    private function allContinuationBytes(string $stream, int $from, int $to): bool
    {
        for ($j = $from; $j < $to; $j++) {
            $c = ord($stream[$j]);
            if ($c < 0x80 || $c > 0xbf) {
                return false;
            }
        }

        return true;
    }

    /**
     * Buffer an incomplete trailing sequence for the next decode() call,
     * bounding $remainder so a never-terminating sequence cannot grow it
     * without limit (a DoS vector — see MAX_SEQUENCE_LENGTH). Oversized tails
     * are dropped; whatever events were already decoded are still returned.
     */
    private function bufferRemainder(string $tail): void
    {
        $this->remainder = strlen($tail) > self::MAX_SEQUENCE_LENGTH ? '' : $tail;
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

        // ESC O — SS3 sequence
        if ($nextOrd === 0x4f) {
            return $this->handleSS3(substr($stream, 2));
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
     * Handle an SS3 (ESC O) sequence.
     *
     * @param string $afterO Bytes after "ESC O"
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleSS3(string $afterO): array
    {
        if ($afterO === '') {
            // Incomplete SS3
            return ['events' => [], 'remaining' => "\x1bO"];
        }

        $final = $afterO[0];
        $ss3Map = [
            'P' => 'F1',
            'Q' => 'F2',
            'R' => 'F3',
            'S' => 'F4',
            // App-cursor-mode arrows (some terminals use SS3 for arrows)
            'A' => 'ArrowUp',
            'B' => 'ArrowDown',
            'C' => 'ArrowRight',
            'D' => 'ArrowLeft',
            'H' => 'Home',
            'F' => 'End',
        ];

        if (!isset($ss3Map[$final])) {
            // Unknown final byte — skip it
            return ['events' => [], 'remaining' => substr($afterO, 1)];
        }

        $raw = "\x1bO" . $final;
        return [
            'events' => [new KeyEvent($ss3Map[$final], KeyModifier::none(), $raw)],
            'remaining' => substr($afterO, 1),
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

        // SGR 1006 mouse: CSI < Pb ; x ; y M|m. When mouse parsing is disabled
        // the sequence flows through to handleCsiKey(), which consumes it as an
        // unrecognized CSI (final byte M|m) and emits nothing.
        if ($afterCsi[0] === '<' && $this->options->enableMouse) {
            return $this->handleSgrMouse(substr($afterCsi, 1));
        }

        // Focus events: CSI I (gained) or CSI O (lost). Disabled → consumed as an
        // unrecognized CSI below.
        if ($this->options->enableFocus && $afterCsi === 'I') {
            return ['events' => [new FocusEvent(true)], 'remaining' => ''];
        }
        if ($this->options->enableFocus && $afterCsi === 'O') {
            return ['events' => [new FocusEvent(false)], 'remaining' => ''];
        }

        // Kitty keyboard protocol: CSI ? Pm ; Ps u. Disabled → consumed as an
        // unrecognized CSI below (final byte u).
        if ($this->options->enableKitty && str_starts_with($afterCsi, '?')) {
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

        // Save original button for motion detection (bit 5 = drag flag)
        $originalButton = $button;

        // Extract modifiers from SGR button field (bit 2=Shift, bit 3=Alt, bit 4=Ctrl)
        // These are added to the base button value, not separate bits to shift
        $modifierBits = ($button >> 2) & 0x07;

        // Scroll events: button 96 = scroll up, 97 = scroll down
        // Modifiers (Shift+4, Alt+8, Ctrl+16) are added to base scroll button
        $modifiers = KeyModifier::fromSgrMouse($modifierBits);
        if ($button === 96 || $button === 100 || $button === 104 || $button === 112 || $button === 116 || $button === 120 || $button === 124) {
            return [
                'events' => [MouseEvent::scrollUp((int)$x, (int)$y, $modifiers)],
                'remaining' => $remaining,
            ];
        }
        if ($button === 97 || $button === 101 || $button === 105 || $button === 113 || $button === 117 || $button === 121 || $button === 125) {
            return [
                'events' => [MouseEvent::scrollDown((int)$x, (int)$y, $modifiers)],
                'remaining' => $remaining,
            ];
        }

        // Regular mouse event: extract base button (bits 0-1)
        $button = $button & 3;

        $modifiers = KeyModifier::fromSgrMouse($modifierBits);
        $isRelease = $isReleaseChar || $button === 3;
        // Motion flag (bit 5 = 32) takes precedence over press
        $isMotion = ($originalButton & 32) !== 0;
        $action = $isRelease ? MouseEvent::ACTION_RELEASE : ($isMotion ? MouseEvent::ACTION_DRAG : MouseEvent::ACTION_PRESS);

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

        // Bracketed paste markers (CSI 200~ / CSI 201~) are handled by decode()
        // as paste sentinels, never as key events. A bare marker only reaches
        // here split across chunks; buffer it so the paste path picks it up.
        // When paste parsing is disabled the markers are not sentinels, so we let
        // them fall through and be consumed as ordinary (ignored) CSI sequences.
        if ($this->options->enablePaste && ($csi === '200~' || $csi === '201~')) {
            return ['events' => [], 'remaining' => "\x1b[" . $csi];
        }

        // Structurally isolate the CSI up to AND INCLUDING its final byte. Per
        // ECMA-48 a CSI (after "ESC [") is: parameter bytes 0x30-0x3F, then
        // intermediate bytes 0x20-0x2F, then exactly one final byte 0x40-0x7E.
        // Anything AFTER the final byte is a genuine suffix — the next key(s) in
        // the same chunk — which we must hand back unconsumed so decodeClean()'s
        // offset walk resumes on it. (Previously the final byte was located by a
        // backward scan that peeled only a single trailing byte, so 2+ printable
        // bytes after a complete/unknown CSI in one chunk were silently dropped.)
        $len = strlen($csi);
        $p = 0;
        while ($p < $len && ($o = ord($csi[$p])) >= 0x30 && $o <= 0x3f) {
            $p++;
        }
        while ($p < $len && ($o = ord($csi[$p])) >= 0x20 && $o <= 0x2f) {
            $p++;
        }
        if ($p >= $len) {
            // Ran out of bytes before a final byte — incomplete sequence.
            // Buffer the whole thing for the next decode() call.
            return ['events' => [], 'remaining' => "\x1b[" . $csi];
        }
        $finalOrd = ord($csi[$p]);
        if ($finalOrd < 0x40 || $finalOrd > 0x7e) {
            // Where the final byte must be, we found a byte outside 0x40-0x7E
            // (a stray control byte, a fresh ESC interrupting the sequence, a
            // high byte, …). The CSI is malformed: drop the "ESC [" + the
            // parameter/intermediate run and resync by reprocessing from the
            // offending byte (this recovers e.g. an ESC that starts a new
            // sequence). $p < $len here, so the returned suffix is non-empty.
            return ['events' => [], 'remaining' => substr($csi, $p)];
        }

        // $seq = the complete CSI body (params + intermediates + final byte);
        // $rest = the untouched suffix returned to the caller.
        $seq  = substr($csi, 0, $p + 1);
        $rest = substr($csi, $p + 1);

        // Modified keys: CSI 1;<mod><final> (xterm format)
        // e.g., CSI 1;2A = Shift+ArrowUp, CSI 1;5C = Ctrl+ArrowRight
        if (preg_match('/^(\d+)(?:;(\d+))?([A-DF-HJ-KP-T])$/', $seq, $m)) {
            $num = (int) $m[1];
            $modRaw = isset($m[2]) ? (int) $m[2] : 1;
            $final = $m[3];

            // xterm modified keys always have num=1 and a modifier parameter
            if ($num === 1 && isset($m[2]) && $modRaw > 1) {
                $modifiers = KeyModifier::fromXtermParam($modRaw);

                $keyMap = [
                    'A' => 'ArrowUp',
                    'B' => 'ArrowDown',
                    'C' => 'ArrowRight',
                    'D' => 'ArrowLeft',
                    'H' => 'Home',
                    'F' => 'End',
                    'P' => 'F1',
                    'Q' => 'F2',
                    'R' => 'F3',
                    'S' => 'F4',
                ];

                if (isset($keyMap[$final])) {
                    return [
                        'events' => [new KeyEvent($keyMap[$final], $modifiers, "\x1b[" . $seq)],
                        'remaining' => $rest,
                    ];
                }
            }
        }

        // Arrow keys: CSI A/B/C/D (a plain arrow has no params, so $seq is one byte)
        $arrowMap = ['A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft'];
        if (isset($arrowMap[$seq])) {
            return [
                'events' => [new KeyEvent($arrowMap[$seq], KeyModifier::none(), "\x1b[" . $seq)],
                'remaining' => $rest,
            ];
        }

        // Home / End: CSI H or CSI F
        if ($seq === 'H') return ['events' => [new KeyEvent('Home', KeyModifier::none(), "\x1b[H")], 'remaining' => $rest];
        if ($seq === 'F') return ['events' => [new KeyEvent('End', KeyModifier::none(), "\x1b[F")], 'remaining' => $rest];

        // Numbered function keys and special keys: 1~, 2~, 3~, 15~, etc.
        if (preg_match('/^(\d+)~$/', $seq, $m)) {
            $num = (int) $m[1];

            $specialKeys = [
                1 => 'Home', 2 => 'Insert', 3 => 'Delete', 4 => 'End',
                5 => 'PageUp', 6 => 'PageDown',
            ];

            if (isset($specialKeys[$num])) {
                return [
                    'events' => [new KeyEvent($specialKeys[$num], KeyModifier::none(), "\x1b[" . $seq)],
                    'remaining' => $rest,
                ];
            }

            // F1-F4 via 11~-14~ (some terminals); F5+ via 15~ onward
            $fKeys = [
                11 => 'F1', 12 => 'F2', 13 => 'F3', 14 => 'F4',
                15 => 'F5', 17 => 'F6', 18 => 'F7', 19 => 'F8',
                20 => 'F9', 21 => 'F10', 23 => 'F11', 24 => 'F12',
                25 => 'F13', 26 => 'F14', 28 => 'F15', 29 => 'F16',
                31 => 'F17', 32 => 'F18', 33 => 'F19', 34 => 'F20',
                35 => 'F21', 36 => 'F22', 37 => 'F23', 38 => 'F24',
            ];

            if (isset($fKeys[$num])) {
                return [
                    'events' => [new KeyEvent($fKeys[$num], KeyModifier::none(), "\x1b[" . $seq)],
                    'remaining' => $rest,
                ];
            }
        }

        // Complete but unrecognized CSI — consume it, emit nothing, and return
        // the genuine suffix so trailing bytes in the same chunk are preserved.
        return ['events' => [], 'remaining' => $rest];
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
            if (strlen($this->pasteBuffer) > PasteEvent::MAX_SIZE) {
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

        // Arrow keys (spec codes)
        $arrowCodes = [
            57399 => 'ArrowUp',
            57400 => 'ArrowDown',
            57401 => 'ArrowRight',
            57402 => 'ArrowLeft',
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

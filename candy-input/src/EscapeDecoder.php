<?php

declare(strict_types=1);

namespace SugarCraft\Input;

use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\TerminalReplyEvent;

/**
 * Terminal escape sequence decoder.
 *
 * Consumes raw bytes via decode() and emits typed Event objects.
 * Handles partial sequences across calls — state is reentrant per instance.
 *
 * Supported sequences:
 *  - Plain ASCII + control codes (Backspace, Tab, Enter, Esc, Ctrl+letter)
 *  - Legacy CSI sequences (arrows, F1-F12, Home/End/PgUp/PgDn, Insert, Delete)
 *  - Kitty keyboard protocol events (CSI code ; mods u — no private prefix)
 *  - SGR 1006 mouse (CSI < button ; x ; y M|m) and X10 mouse (CSI M b x y)
 *  - Focus events (CSI I / CSI O)
 *  - Bracketed paste (CSI 200 ~ ... CSI 201 ~)
 *  - Unsolicited terminal REPLIES (DA1/DA2, DSR/CPR, XTWINOPS, kitty flags,
 *    DECRPM, OSC/DCS/APC/PM strings) — structurally consumed and surfaced as
 *    {@see \SugarCraft\Input\Event\TerminalReplyEvent}, never left in the
 *    buffer and never mis-parsed as a keystroke.
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

    /**
     * Upper bound on how many bytes of an OSC/DCS/APC/PM STRING payload we will
     * accumulate before draining the sequence as truncated. Legitimate replies
     * (XTGETTCAP, DECRQSS, color reports) are tens of bytes; the cap exists only
     * so a hostile never-terminated `ESC P` cannot grow memory without limit —
     * the same rationale as MAX_SEQUENCE_LENGTH, sized for string payloads.
     */
    private const MAX_STRING_LENGTH = 1024;

    /**
     * Upper bound on how many bytes past MAX_STRING_LENGTH we keep swallowing
     * from an unterminated OSC/DCS/APC/PM string before giving up and resuming
     * normal decoding. A legit long reply (multi-capability XTGETTCAP) stays
     * drained; a truly unterminated hostile flood is bounded and the decoder
     * resyncs on its own instead of poisoning the stream forever.
     */
    private const MAX_STRING_ABANDON = 65536;

    /** Remaining bytes after last decode() that couldn't form a complete sequence */
    private string $remainder = '';

    /** Paste accumulation buffer */
    private string $pasteBuffer = '';

    /** Whether we are currently inside a bracketed paste */
    private bool $inPaste = false;

    /** Whether we are mid OSC/DCS/APC/PM string (payload accumulates in $stringBuffer) */
    private bool $inString = false;

    /** The 2-byte introducer ("ESC" + "]" "P" "_" "^") of the in-progress string */
    private string $stringIntroducer = '';

    /** Accumulated payload bytes of the in-progress string, excluding introducer (capped) */
    private string $stringBuffer = '';

    /** True once a string reply exceeded MAX_STRING_LENGTH and was drained as truncated */
    private bool $stringOverflow = false;

    /** Bytes swallowed past the cap while draining an over-long string toward its terminator */
    private int $stringDiscarded = 0;

    /** Lifetime count of terminal replies drained into TerminalReplyEvent */
    private int $drainedReplyCount = 0;

    /** Lifetime count of complete-but-unrecognized sequences consumed silently */
    private int $droppedUnknownCount = 0;

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

        $events = [];

        // One coalesced read can carry several complete paste episodes (a
        // terminal pasting text that itself contains "ESC [ 201 ~ ESC [ 200 ~")
        // or a string terminator followed by more sequences. Re-enter the loop
        // with the pass's tail instead of recursing into decode(), so stack
        // depth stays independent of how many episodes one read carries —
        // unbounded recursion segfaults the process on a hostile pipe.
        while ($stream !== '') {
            if ($this->inPaste === true) {
                $pass = $this->handlePastePass($stream);
            } elseif ($this->inString === true) {
                // In-progress OSC/DCS/APC/PM string — every byte belongs to the
                // payload until its terminator; never decoded as keys.
                $pass = $this->consumeStringPayload($stream);
            } else {
                // Check for paste start anywhere in stream (only when paste
                // parsing is enabled; otherwise the markers fall through as
                // ordinary — and ignored — CSI sequences below).
                $pasteStartPos = $this->options->enablePaste
                    ? strpos($stream, self::PASTE_START)
                    : false;
                if ($pasteStartPos === false) {
                    return array_merge($events, $this->decodeClean($stream));
                }

                // Decode any bytes before the paste start normally
                $prefix = substr($stream, 0, $pasteStartPos);
                if ($prefix !== '') {
                    $events = array_merge($events, $this->decodeClean($prefix));
                }

                $this->pasteBuffer = '';
                $this->inPaste = true;
                $pass = $this->finishPastePass(substr($stream, $pasteStartPos + strlen(self::PASTE_START)));
            }

            // Append per-event (not array_merge) so a flood of tiny passes —
            // thousands of paste pairs in one read — stays amortized O(1).
            foreach ($pass['events'] as $event) {
                $events[] = $event;
            }
            if ($pass['remaining'] === '') {
                return $events;
            }
            $stream = $pass['remaining'];
        }

        return $events;
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
            if ($this->options->deferTrailingEscape) {
                // A chunk that ends on ESC may be the split of a longer escape
                // sequence (slow TTY reads end after ESC routinely). Return it
                // un-consumed so decodeClean buffers it; the app resolves it
                // with flushDeferredEscape() once no follower arrives (bubbletea
                // uses the same timeout strategy).
                return ['events' => [], 'remaining' => "\x1b"];
            }

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

        // ESC ] / ESC P / ESC _ / ESC ^ — OSC / DCS / APC / PM string sequences.
        // A terminal answers color/capability queries (e.g. OSC 4 replies) on the
        // SAME fd the user types on; these payloads are replies, never keystrokes —
        // drain them to their terminator as a TerminalReplyEvent instead of leaking
        // the body as Alt-key spam.
        // @see xterm ctlseqs — OSC/DCS/APC/PM framing with ST or BEL terminators.
        if ($next === ']' || $next === 'P' || $next === '_' || $next === '^') {
            return $this->handleStringStart($stream);
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
            // Application keypad mode: ESC O M is keypad Enter.
            // @see xterm ctlseqs — "ESC O ... (SS3)".
            'M' => 'Enter',
        ];

        if (!isset($ss3Map[$final])) {
            // Unknown final byte — skip it
            $this->droppedUnknownCount++;
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

        // X10 mouse: CSI M Pb Px Py — three raw payload bytes (each value + 32).
        // Without this branch the final byte 'M' is consumed as an unrecognized
        // CSI and the three payload bytes leak as printable KeyEvents
        // ("\x1b[M #%" → Key(' '), Key('#'), Key('%')). The bytes are consumed
        // even when mouse parsing is disabled, so the stream stays in sync.
        // @see xterm ctlseqs — "X10 mouse reporting".
        if ($afterCsi[0] === 'M') {
            return $this->handleX10Mouse(substr($afterCsi, 1));
        }

        // Standard CSI key sequences (also classifies focus events and every
        // unsolicited terminal reply family).
        return $this->handleCsiKey($afterCsi);
    }

    /**
     * Handle SGR 1006 mouse sequence: CSI < Pb ; Px ; Py M|m.
     *
     * @param string $afterLt Bytes after the "<"
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleSgrMouse(string $afterLt): array
    {
        // Find the FIRST final byte: 'M' (press/motion) or 'm' (release).
        // Searching for 'M' unconditionally first would let a chunk carrying a
        // release followed by a press ("…1m…2M") match the LATER 'M' as the end
        // of the first report, turning both events into one malformed drop.
        $mPos = strpos($afterLt, 'M');
        $lowerPos = strpos($afterLt, 'm');
        $endPos = match (true) {
            $mPos !== false && $lowerPos !== false => min($mPos, $lowerPos),
            $mPos !== false => $mPos,
            $lowerPos !== false => $lowerPos,
            default => false,
        };
        if ($endPos === false) {
            // Incomplete
            return ['events' => [], 'remaining' => "\x1b[<" . $afterLt];
        }
        $isReleaseChar = $afterLt[$endPos] === 'm';

        $params = substr($afterLt, 0, $endPos);
        $remaining = substr($afterLt, $endPos + 1);

        $parts = explode(';', $params);
        if (count($parts) !== 3) {
            // Malformed (wrong parameter count) but COMPLETE — consume through the
            // final byte and resync on the genuine suffix. Returning the whole tail
            // here would re-buffer a terminated sequence forever and stall every
            // keystroke behind it in the same stream.
            $this->droppedUnknownCount++;
            return ['events' => [], 'remaining' => $remaining];
        }

        [$btnRaw, $x, $y] = $parts;
        $event = $this->mouseFromButtonCode((int) $btnRaw, (int) $x, (int) $y, $isReleaseChar);

        return ['events' => [$event], 'remaining' => $remaining];
    }

    /**
     * Handle X10 mouse sequence: CSI M Pb Px Py (three raw payload bytes,
     * each value biased by +32).
     *
     * @param string $afterM Bytes after the "M"
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleX10Mouse(string $afterM): array
    {
        if (strlen($afterM) < 3) {
            // Payload may legitimately contain any byte ≥ 0x20; a control byte in
            // a payload slot means the report was truncated — resync on it rather
            // than swallowing a fresh ESC sequence.
            for ($j = 0; $j < strlen($afterM); $j++) {
                if (ord($afterM[$j]) < 0x20) {
                    $this->droppedUnknownCount++;
                    return ['events' => [], 'remaining' => substr($afterM, $j)];
                }
            }
            // Incomplete — wait for the rest of the payload.
            return ['events' => [], 'remaining' => "\x1b[M" . $afterM];
        }

        for ($j = 0; $j < 3; $j++) {
            if (ord($afterM[$j]) < 0x20) {
                $this->droppedUnknownCount++;
                return ['events' => [], 'remaining' => substr($afterM, $j)];
            }
        }

        if (!$this->options->enableMouse) {
            // Structurally consumed, no event — stream stays in sync.
            return ['events' => [], 'remaining' => substr($afterM, 3)];
        }

        $buttonCode = ord($afterM[0]) - 32;
        $x = ord($afterM[1]) - 32;
        $y = ord($afterM[2]) - 32;

        // X10 encodes release as button code & 3 === 3 (no separate final byte).
        $event = $this->mouseFromButtonCode($buttonCode, $x, $y, false);

        return ['events' => [$event], 'remaining' => substr($afterM, 3)];
    }

    /**
     * Map an SGR/X10 button code to a MouseEvent.
     *
     * Bit layout per xterm SGR 1006 (the same codes appear X10-biased in mode 1000):
     *  - bits 0-1: button number (3 = release in X10)
     *  - bit 2/3/4 (values 4/8/16): Shift / Alt / Ctrl
     *  - bit 5 (value 32): motion (drag)
     *  - bit 6 (value 64): wheel — bit 1 selects left/right, bit 0 up/down
     *
     * A wheel code (64/65/66/67, optionally | 32 | modifiers) must NEVER fall
     * through to the plain-button path: `code & 3` of wheel-up (64) is 0, which
     * used to surface a phantom left-click for every plain wheel event.
     *
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html — SGR mouse.
     */
    private function mouseFromButtonCode(int $code, int $x, int $y, bool $isReleaseChar): MouseEvent
    {
        $modifiers = KeyModifier::fromSgrMouse(($code >> 2) & 0x07);

        if (($code & 64) !== 0) {
            // Wheel family (bit 6): horizontal when bit 1 set; direction bit 0.
            if (($code & 2) !== 0) {
                return ($code & 1) === 0
                    ? MouseEvent::scrollLeft($x, $y, $modifiers)
                    : MouseEvent::scrollRight($x, $y, $modifiers);
            }

            return ($code & 1) === 0
                ? MouseEvent::scrollUp($x, $y, $modifiers)
                : MouseEvent::scrollDown($x, $y, $modifiers);
        }

        $button = $code & 3;
        $isRelease = $isReleaseChar || $button === 3;
        $isMotion = ($code & 32) !== 0;
        $action = $isRelease
            ? MouseEvent::ACTION_RELEASE
            : ($isMotion ? MouseEvent::ACTION_DRAG : MouseEvent::ACTION_PRESS);

        return new MouseEvent($x, $y, $button, $action, $modifiers);
    }

    /**
     * Handle a Kitty keyboard protocol EVENT frame: CSI unicode-key-code
     * [:shift-code[:base-code...]] ; modifiers[:event-type] u
     *
     * Real kitty frames carry NO private-mode "?" prefix — `CSI ? … u` is the
     * keyboard-flags QUERY/REPLY family and is drained as a TerminalReplyEvent
     * instead (see classifyPrivateReply). The old decoder had this inverted:
     * it parsed keys only behind "?" (so "\x1b[97;5u" produced nothing) and
     * mis-executed parameter consumption on "?" frames.
     *
     * Release is recognized both in the legacy encoding (modifiers OR 0x20) and
     * in the spec encoding (event-type sub-parameter 3; 1=press, 2=repeat).
     * Alternate key codes after the first colon in the code field are ignored —
     * the primary code identifies the key. Event type 2 (auto-repeat) is
     * deliberately surfaced as an ordinary press: a repeat IS a keystroke for
     * every TUI consumer of this decoder, and inventing a "Repeat"-prefixed key
     * name would widen the event vocabulary for no gain.
     *
     * @param string $seq  Complete CSI body ending in "u" (e.g. "97;5u", "97:65;2:3u")
     * @param string $rest Genuine suffix after the sequence
     * @return array{events: list<Event>, remaining: string}
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ — the CSI u encoding.
     */
    private function handleKittyEvent(string $seq, string $rest): array
    {
        // Isolate params (drop the final "u"), then the two ';' fields with
        // their ':' sub-parameters.
        $body = substr($seq, 0, -1);
        $fields = explode(';', $body, 2);
        $codePart = explode(':', $fields[0]);
        $codeRaw = $codePart[0] === '' ? 0 : (int) $codePart[0];

        $modRaw = 0;
        $eventType = 0;
        if (isset($fields[1])) {
            $modPart = explode(':', $fields[1]);
            $modRaw = $modPart[0] === '' ? 0 : (int) $modPart[0];
            if (isset($modPart[1]) && $modPart[1] !== '') {
                $eventType = (int) $modPart[1];
            }
        }

        // The wire modifier field is 1 + the actual bitmask (a bare press is
        // "1", not "0") — de-base before interpreting any bit. When an explicit
        // event-type sub-param is present, release is event-type 3 and the full
        // spec mask (incl. bit 5 = Meta) maps to modifiers; in the legacy form
        // the 0x20 bit doubles as the release flag and cannot carry Meta.
        $mask = max(0, $modRaw - 1);
        if ($eventType !== 0) {
            $isRelease = $eventType === 3;
            $modifiers = KeyModifier::fromKittyInt($mask);
        } else {
            $isRelease = ($mask & 0x20) !== 0;
            $modifiers = KeyModifier::fromKittyInt($mask & 0x1f);
        }

        $keyName = $codeRaw === 0 ? null : $this->kittyKeyCodeToName($codeRaw);
        if ($keyName === null) {
            // Complete but unknown key code — consume it, count the drop, and
            // hand the suffix back intact (returning '' here used to swallow
            // every byte that followed in the same chunk).
            $this->droppedUnknownCount++;
            return ['events' => [], 'remaining' => $rest];
        }

        $fullKey = $isRelease ? 'Release' . ucfirst($keyName) : $keyName;
        $raw = "\x1b[" . $seq;

        return [
            'events' => [new KeyEvent($fullKey, $modifiers, $raw)],
            'remaining' => $rest,
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

        // A stray paste-END marker outside a paste is terminal noise: consume it
        // (counted as a drop) rather than parking it in the remainder forever.
        // A full paste-START never reaches here — decode()'s sentinel scan owns
        // it — and a partial marker is held back by splitCsi()'s incomplete path.
        if ($this->options->enablePaste && str_starts_with($csi, '201~')) {
            $this->droppedUnknownCount++;

            return ['events' => [], 'remaining' => substr($csi, 4)];
        }

        $split = $this->splitCsi($csi);

        // Guard: ran out of bytes before a final byte — buffer for the next call.
        if ($split['state'] === 'incomplete') {
            return ['events' => [], 'remaining' => "\x1b[" . $csi];
        }

        // Guard: a byte outside the CSI grammar sits where the final byte must be
        // (stray control, fresh ESC, high byte). Malformed — drop the broken CSI
        // and resync by reprocessing from the offending byte onward.
        if ($split['state'] === 'malformed') {
            return $this->dropUnknown($split['suffix']);
        }

        return $this->handleCsiParsed($split['seq'], $split['rest']);
    }

    /**
     * Structurally isolate the CSI body up to AND INCLUDING its final byte. Per
     * ECMA-48 a CSI (after "ESC [") is: parameter bytes 0x30-0x3F, then
     * intermediate bytes 0x20-0x2F, then exactly one final byte 0x40-0x7E.
     * Anything AFTER the final byte is a genuine suffix — the next key(s) in the
     * same chunk — handed back so decodeClean()'s offset walk resumes on it.
     *
     * @return array{state:'incomplete'}
     *       | array{state:'malformed',suffix:string}
     *       | array{state:'ok',seq:string,rest:string}
     */
    private function splitCsi(string $csi): array
    {
        $len = strlen($csi);
        $p = 0;
        while ($p < $len && ($o = ord($csi[$p])) >= 0x30 && $o <= 0x3f) {
            $p++;
        }
        while ($p < $len && ($o = ord($csi[$p])) >= 0x20 && $o <= 0x2f) {
            $p++;
        }
        if ($p >= $len) {
            return ['state' => 'incomplete'];
        }
        if (ord($csi[$p]) < 0x40 || ord($csi[$p]) > 0x7e) {
            return ['state' => 'malformed', 'suffix' => substr($csi, $p)];
        }

        return ['state' => 'ok', 'seq' => substr($csi, 0, $p + 1), 'rest' => substr($csi, $p + 1)];
    }

    /**
     * Decode a CSI whose grammar boundary is already parsed.
     *
     * @param string $seq  Complete CSI body (params + intermediates + final byte)
     * @param string $rest Untouched suffix of the chunk after this sequence
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleCsiParsed(string $seq, string $rest): array
    {
        $final = substr($seq, -1);
        $prefixByte = $seq[0];
        $intermediates = preg_match('/^[\x30-\x3f]*([\x20-\x2f]*)/', $seq, $im) ? $im[1] : '';
        $params = preg_match('/^([\x30-\x3f]*)/', $seq, $pm) ? $pm[1] : '';
        $hasParams = $params !== '' && preg_match('/\d/', $params) === 1;

        // ── Unsolicited terminal replies — drain, never keystroke, never stall ──

        // Private-mode CSI (first parameter byte '?' or '>'): per ECMA-48 a private
        // sequence can never be a keystroke. Older builds buffered these forever,
        // poisoning every subsequent keypress until the 128-byte cap dropped the
        // stream ("the keyboard hangs after any DA1/DECRPM/kitty-flags reply").
        if ($prefixByte === '?' || $prefixByte === '>') {
            return $this->drainReply($this->classifyPrivateReply($seq, $final, $intermediates), $seq, $rest);
        }

        // CPR / DSR cursor position report: CSI row ; col R. Must be checked
        // BEFORE the xterm modified-key path — "1;20R" otherwise fabricates a
        // phantom F3 with modifiers for the DSR reply to any ESC [ 6 n query.
        // (A plain F3 arrives as SS3 ESC O R, untouched here.)
        // @see xterm ctlseqs — "CSI Ps ; Ps R" cursor position report.
        if ($final === 'R' && $hasParams) {
            return $this->drainReply(
                TerminalReplyEvent::FAMILY_CURSOR_POSITION,
                $seq,
                $rest,
            );
        }

        // DSR status report: CSI 0 n (reply to "is term ok?" ESC [ 5 n).
        if ($final === 'n') {
            return $this->drainReply(
                TerminalReplyEvent::FAMILY_DSR_STATUS,
                $seq,
                $rest,
            );
        }

        // XTWINOPS window reports: CSI Ps ; Ps ; Ps t (reply to resize/title queries).
        // @see xterm ctlseqs — "Window manipulation" reports ending in "t".
        if ($final === 't') {
            return $this->drainReply(
                TerminalReplyEvent::FAMILY_WINDOW_REPORT,
                $seq,
                $rest,
            );
        }

        // DECRPM mode report with a public '$' intermediate: CSI mode ; status $ y.
        if ($final === 'y' && $intermediates === '$') {
            return $this->drainReply(
                TerminalReplyEvent::FAMILY_MODE_REPORT,
                $seq,
                $rest,
            );
        }

        // Device Attributes reply without the '?'/'>' prefix (VT52-flavored or
        // soft-trigger echo): CSI c / CSI 61 ; 1 ; 0 c. Never a keystroke.
        if ($final === 'c') {
            return $this->drainReply(
                TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES,
                $seq,
                $rest,
            );
        }

        // Kitty keyboard EVENT frame: CSI code[:alts] ; mods[:event-type] u —
        // NO private prefix (that is the flags reply family handled above).
        if ($final === 'u') {
            return $this->options->enableKitty
                ? $this->handleKittyEvent($seq, $rest)
                : $this->dropUnknown($rest);
        }

        // ── Focus reports: bare CSI I (gained) / CSI O (lost) ──────────────
        // Recognized structurally at the final byte, so a focus report followed
        // by more bytes in the SAME chunk ("\x1b[Iabc") no longer loses the
        // event to an exact-string comparison against the whole tail.
        if ($this->options->enableFocus && $seq === 'I') {
            return ['events' => [new FocusEvent(true)], 'remaining' => $rest];
        }
        if ($this->options->enableFocus && $seq === 'O') {
            return ['events' => [new FocusEvent(false)], 'remaining' => $rest];
        }

        // Modified keys: CSI 1;<mod><final> (xterm format)
        // e.g., CSI 1;2A = Shift+ArrowUp, CSI 1;5C = Ctrl+ArrowRight
        // ("R" excluded: F3-with-modifier never wins against CPR ambiguity —
        // parameterized R is always drained as a cursor-position report above.)
        if (preg_match('/^(\d+)(?:;(\d+))?([A-DF-HJ-KP-T])$/', $seq, $m)) {
            $num = (int) $m[1];
            $modRaw = $m[2] === '' ? 1 : (int) $m[2];
            $finalKey = $m[3];

            // xterm modified keys always have num=1 and a modifier parameter
            if ($num === 1 && $m[2] !== '' && $modRaw > 1) {
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
                    'S' => 'F4',
                ];

                if (isset($keyMap[$finalKey])) {
                    return [
                        'events' => [new KeyEvent($keyMap[$finalKey], $modifiers, "\x1b[" . $seq)],
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

        // Backtab: CSI Z (shift-TAB, sent by xterm & friends)
        if ($seq === 'Z') {
            return ['events' => [new KeyEvent('Backtab', KeyModifier::shift(), "\x1b[Z")], 'remaining' => $rest];
        }

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
        return $this->dropUnknown($rest);
    }

    /**
     * One pass over a stream while a bracketed paste is in progress: check for
     * the paste-end marker and report the tail after it back to decode()'s
     * loop (never recursing — see the note there on stack depth).
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function handlePastePass(string $stream): array
    {
        $pasteEndPos = strpos($stream, self::PASTE_END);
        if ($pasteEndPos === false) {
            // The end marker itself may be split at the chunk boundary
            // ("…\x1b[201" + "~…"): hold back that trailing partial marker so the
            // next decode() reassembles it whole. Swallowing it into the paste
            // body instead would lose the marker and keep the paste open
            // forever, eating every later keystroke.
            $partial = $this->trailingPartialMarker($stream, self::PASTE_END);
            if ($partial > 0) {
                $this->remainder = substr($stream, -$partial);
                $stream = substr($stream, 0, -$partial);
            }

            // Not complete — accumulate
            if ($stream !== '') {
                $this->pasteBuffer .= $stream;
            }
            if (strlen($this->pasteBuffer) > PasteEvent::MAX_SIZE) {
                // Force-close on oversized paste. Leave any held-back partial end
                // marker in $remainder untouched — wiping it would turn the
                // marker split across the overflow boundary into literal keys.
                $event = PasteEvent::truncate($this->pasteBuffer);
                $this->pasteBuffer = '';
                $this->inPaste = false;

                return ['events' => [$event], 'remaining' => ''];
            }

            return ['events' => [], 'remaining' => ''];
        }

        $pasteContent = $this->pasteBuffer . substr($stream, 0, $pasteEndPos);
        $this->pasteBuffer = '';
        $this->inPaste = false;

        // Hand the post-paste tail back to decode()'s loop rather than parking
        // it in $remainder: a long tail (bytes that legitimately follow the end
        // marker in the same read) stays bounded by the sequence cap applied to
        // its incomplete suffix.
        return [
            'events' => [PasteEvent::truncate($pasteContent)],
            'remaining' => substr($stream, $pasteEndPos + strlen(self::PASTE_END)),
        ];
    }

    /**
     * Finish paste — first pass after a paste-start marker: check if the bytes
     * contain the paste end and report the tail back to decode()'s loop.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function finishPastePass(string $afterStart): array
    {
        $pasteEndPos = strpos($afterStart, self::PASTE_END);
        if ($pasteEndPos === false) {
            // Same split-marker holdback as handlePastePass().
            $partial = $this->trailingPartialMarker($afterStart, self::PASTE_END);
            if ($partial > 0) {
                $this->remainder = substr($afterStart, -$partial);
                $afterStart = substr($afterStart, 0, -$partial);
            }
            $this->pasteBuffer .= $afterStart;
            if (strlen($this->pasteBuffer) > PasteEvent::MAX_SIZE) {
                // Same oversized force-close as handlePastePass(); a held-back
                // partial end marker in $remainder survives untouched.
                $event = PasteEvent::truncate($this->pasteBuffer);
                $this->pasteBuffer = '';
                $this->inPaste = false;

                return ['events' => [$event], 'remaining' => ''];
            }

            return ['events' => [], 'remaining' => ''];
        }

        $pasteContent = $this->pasteBuffer . substr($afterStart, 0, $pasteEndPos);
        $this->pasteBuffer = '';
        $this->inPaste = false;

        return [
            'events' => [PasteEvent::truncate($pasteContent)],
            'remaining' => substr($afterStart, $pasteEndPos + strlen(self::PASTE_END)),
        ];
    }

    /**
     * Classify a complete private-mode CSI ("ESC [" + "?" / ">" params + final).
     *
     * A private-mode sequence on the INPUT stream is a terminal reply (or an
     * echo of a query) — never a keystroke. Families per xterm ctlseqs / kitty
     * keyboard protocol:
     *  - final "c"   → DA1 (`CSI ? Ps ; Ps c`) / DA2 (`CSI > Ps ; Ps c`) reply
     *  - final "u"   → kitty keyboard-flags reply (`CSI ? flags u`); the bare
     *                  empty-codepoint `CSI ? u` form carries no flags
     *  - "$" + "y"   → DECRPM mode report (`CSI ? mode ; status $ y`)
     *  - final "t"   → private XTWINOPS variant (drained as window-report)
     *  - anything else → drained as csi-private
     *
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ — "Reviewing feature flags".
     */
    private function classifyPrivateReply(string $seq, string $final, string $intermediates): string
    {
        if ($final === 'y' && $intermediates === '$') {
            return TerminalReplyEvent::FAMILY_MODE_REPORT;
        }

        return match ($final) {
            'c' => TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES,
            'u' => TerminalReplyEvent::FAMILY_KITTY_FLAGS,
            't' => TerminalReplyEvent::FAMILY_WINDOW_REPORT,
            default => TerminalReplyEvent::FAMILY_CSI_PRIVATE,
        };
    }

    /**
     * Consume a recognized terminal reply and surface it as a TerminalReplyEvent.
     * Numeric parameters are the digit runs of the CSI body, in wire order.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function drainReply(string $family, string $seq, string $rest): array
    {
        $params = [];
        preg_match_all('/\d+/', $seq, $numeric);
        foreach ($numeric[0] as $value) {
            $params[] = (int) $value;
        }

        $this->drainedReplyCount++;

        return [
            'events' => [new TerminalReplyEvent($family, $params, "\x1b[" . $seq)],
            'remaining' => $rest,
        ];
    }

    /**
     * Consume a complete-but-unrecognized sequence: no event, counted so hosts
     * can distinguish silent drops from drained replies.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function dropUnknown(string $rest): array
    {
        $this->droppedUnknownCount++;

        return ['events' => [], 'remaining' => $rest];
    }

    /**
     * Begin an OSC/DCS/APC/PM string sequence (ESC followed by ] P _ ^).
     *
     * Consume payload bytes up to the terminator — ST ("ESC \") or BEL. The
     * whole payload is a reply body (color report, capability answer, termcap
     * hex) and must never surface as keystrokes.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function handleStringStart(string $stream): array
    {
        $this->inString = true;
        $this->stringIntroducer = substr($stream, 0, 2);
        $this->stringBuffer = '';

        return $this->consumeStringPayload(substr($stream, 2));
    }

    /**
     * Scan payload bytes for a string terminator; keep buffering (capped) while
     * the terminator has not arrived (see MAX_STRING_LENGTH). Bytes after the
     * terminator are reported back as `remaining` for decode()'s loop — the
     * reply may have arrived mid-keystroke-stream, so trailing input must
     * still be decoded.
     *
     * @return array{events: list<Event>, remaining: string}
     */
    private function consumeStringPayload(string $fresh): array
    {
        $haystack = $this->stringBuffer . $fresh;
        $payloadStart = strlen($haystack) - strlen($fresh);

        // A terminator ESC may itself have been split across chunks, so re-scan
        // from one byte before the fresh payload.
        $scanFrom = max(0, $payloadStart - 1);
        $belPos = strpos($haystack, "\x07", $scanFrom);
        $stPos = strpos($haystack, "\x1b\\", $scanFrom);

        $endPos = match (true) {
            $belPos !== false && $stPos !== false => min($belPos, $stPos),
            $belPos !== false => $belPos,
            $stPos !== false => $stPos,
            default => false,
        };

        if ($endPos === false) {
            if ($this->stringOverflow === true) {
                // Past the cap already: payload is discarded, not accumulated —
                // we are only waiting for the terminator (never resume key
                // decoding mid-string). One byte is still carried: a chunk that
                // ends exactly on the ST's ESC must not lose the terminator, or
                // every later keystroke would be swallowed until the next
                // abandon boundary.
                $carry = $haystack !== '' && $haystack[strlen($haystack) - 1] === "\x1b" ? 1 : 0;
                if ($this->stringDiscarded + strlen($haystack) > self::MAX_STRING_ABANDON) {
                    // A stream that never terminates the string must not silence
                    // keystrokes forever — abandon and resync. Replay only the
                    // bytes past the boundary so chunk sizes cannot change the
                    // event stream.
                    $replay = $this->stringDiscarded + strlen($haystack) - self::MAX_STRING_ABANDON;
                    $this->inString = false;
                    $this->stringOverflow = false;
                    $this->stringBuffer = '';
                    $this->stringDiscarded = 0;

                    return [
                        'events' => [],
                        'remaining' => substr($haystack, strlen($haystack) - $replay),
                    ];
                }
                $this->stringDiscarded += strlen($haystack) - $carry;
                $this->stringBuffer = $carry === 1 ? "\x1b" : '';

                return ['events' => [], 'remaining' => ''];
            }

            $this->stringBuffer .= $fresh;

            // A read boundary can split the ST terminator between its ESC and the
            // backslash. Do not let that dangling ESC breach the cap on its own:
            // a reply of exactly MAX_STRING_LENGTH bytes must drain whole and
            // un-truncated no matter where the reads happened to fall.
            $breached = strlen($this->stringBuffer) > self::MAX_STRING_LENGTH
                && !($this->stringBuffer[strlen($this->stringBuffer) - 1] === "\x1b"
                    && strlen($this->stringBuffer) === self::MAX_STRING_LENGTH + 1);

            if ($breached) {
                // Cap breached: drain the head as one truncated reply, then keep
                // swallowing the rest of the payload until its terminator. The
                // old behaviour cleared the string state outright, so every
                // later byte of a >1 KiB OSC/DCS reply leaked back as keys.
                $overflowed = $this->stringBuffer;
                $body = substr($overflowed, 0, self::MAX_STRING_LENGTH);
                $tail = substr($overflowed, self::MAX_STRING_LENGTH);
                $this->stringBuffer = '';
                $this->stringOverflow = true;
                $this->drainedReplyCount++;
                $event = new TerminalReplyEvent(
                    TerminalReplyEvent::FAMILY_STRING,
                    [],
                    $this->stringIntroducer . $body,
                    $body,
                    truncated: true,
                );

                if (strlen($tail) > self::MAX_STRING_ABANDON) {
                    // A single chunk already blew past the abandon budget — give
                    // up exactly as the incremental path would, so the byte
                    // size of a read cannot change the event stream.
                    $this->inString = false;
                    $this->stringOverflow = false;
                    $this->stringDiscarded = 0;

                    return [
                        'events' => [$event],
                        'remaining' => substr($overflowed, self::MAX_STRING_LENGTH + self::MAX_STRING_ABANDON),
                    ];
                }

                // Same trailing-ESC carry as the incremental drain branch, so a
                // terminator split right after the cap boundary still lands.
                $carry = $tail !== '' && $tail[strlen($tail) - 1] === "\x1b" ? 1 : 0;
                $this->stringDiscarded = strlen($tail) - $carry;
                $this->stringBuffer = $carry === 1 ? "\x1b" : '';

                return ['events' => [$event], 'remaining' => ''];
            }

            return ['events' => [], 'remaining' => ''];
        }

        $truncated = $this->stringOverflow;
        $terminator = $haystack[$endPos] === "\x07" ? "\x07" : "\x1b\\";
        $remaining = substr($haystack, $endPos + strlen($terminator));

        $this->inString = false;
        $this->stringOverflow = false;
        $this->stringDiscarded = 0;
        $body = $truncated ? '' : substr($haystack, 0, $endPos);
        $this->stringBuffer = '';

        if ($truncated === true) {
            // The truncated reply was already emitted at the cap; swallow the
            // terminator quietly so it cannot surface as Alt+key.
            return ['events' => [], 'remaining' => $remaining];
        }

        // A single read that carries both a >1 KiB body and its terminator must
        // drain the SAME bounded reply the incremental (chunked) path produces —
        // clip the body and flag it, so read size cannot change the event.
        if (strlen($body) > self::MAX_STRING_LENGTH) {
            $truncated = true;
            $body = substr($body, 0, self::MAX_STRING_LENGTH);
        }

        $this->drainedReplyCount++;

        return [
            'events' => [new TerminalReplyEvent(
                TerminalReplyEvent::FAMILY_STRING,
                [],
                $this->stringIntroducer . $body . $terminator,
                $body,
                truncated: $truncated,
            )],
            'remaining' => $remaining,
        ];
    }

    /**
     * Length of the longest STRICT suffix of $stream that is a proper prefix of
     * $marker — i.e. the number of trailing bytes that could be the beginning of
     * the marker split across reads (0 when nothing can be held back).
     */
    private function trailingPartialMarker(string $stream, string $marker): int
    {
        $max = min(strlen($stream), strlen($marker) - 1);
        for ($len = $max; $len > 0; $len--) {
            if (substr($marker, 0, $len) === substr($stream, -$len)) {
                return $len;
            }
        }

        return 0;
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
     * Lifetime count of unsolicited terminal replies drained into
     * TerminalReplyEvent. Distinguishes "recognized and drained" from the
     * silent drops counted by droppedUnknownCount().
     */
    public function drainedReplyCount(): int
    {
        return $this->drainedReplyCount;
    }

    /**
     * Lifetime count of complete-but-unrecognized sequences consumed with no
     * event (unknown CSI finals, malformed runs, unknown kitty key codes).
     */
    public function droppedUnknownCount(): int
    {
        return $this->droppedUnknownCount;
    }

    /**
     * Resolve a lone trailing ESC deferred by the `deferTrailingEscape` option
     * into the Escape key it (probably) was. Call from an application timer once
     * no more input arrives within the escape-timeout window; if the buffered
     * tail is exactly one ESC byte, clear it and return that KeyEvent.
     *
     * A bare ESC also sits in the buffer while a bracketed paste is open (it is
     * the held-back prefix of a split end marker) — flushing there would invent
     * an Escape and strand the paste forever, so the buffer is only trusted as a
     * deferred keystroke between episodes.
     *
     * @return list<Event> one Escape event, or empty when nothing is deferred
     */
    public function flushDeferredEscape(): array
    {
        if ($this->inPaste === true || $this->inString === true) {
            return [];
        }
        if ($this->remainder !== "\x1b") {
            return [];
        }

        $this->remainder = '';

        return [new KeyEvent('Escape', KeyModifier::none(), "\x1b")];
    }

    /**
     * Clear the partial-sequence buffer, paste/string state, and reply counters.
     */
    public function reset(): void
    {
        $this->remainder = '';
        $this->pasteBuffer = '';
        $this->inPaste = false;
        $this->inString = false;
        $this->stringIntroducer = '';
        $this->stringBuffer = '';
        $this->stringOverflow = false;
        $this->stringDiscarded = 0;
        $this->drainedReplyCount = 0;
        $this->droppedUnknownCount = 0;
    }
}

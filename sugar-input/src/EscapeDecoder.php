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
 *  - Legacy CSI sequences (arrows, F1-F12, Home/End/PgUp/PgDn, Insert, Delete,
 *    Backtab CSI Z)
 *  - Kitty keyboard protocol events (CSI code ; mods u — no private prefix)
 *  - xterm modifyOtherKeys reports (CSI 27 ; mods ; keysym ~)
 *  - Mouse: SGR 1006 / 1016 (CSI < b ; x ; y M|m), urxvt 1015
 *    (CSI b ; x ; y M), and X10 (CSI M b x y)
 *  - SS3 keys incl. application-keypad mode (ESC O p..y, l, m, M — DECKPAM)
 *  - Focus events (CSI I / CSI O)
 *  - Bracketed paste (CSI 200 ~ ... CSI 201 ~)
 *  - Unsolicited terminal REPLIES (DA1/DA2, DSR/CPR, XTWINOPS, Kitty flags,
 *    DECRPM, OSC/DCS/APC/PM strings) — structurally consumed and surfaced as
 *    {@see \SugarCraft\Input\Event\TerminalReplyEvent}, never left in the
 *    buffer and never mis-parsed as a keystroke.
 *
 * Mirrors charmbracelet/bubbletea's input handling.
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

    /**
     * SS3 (ESC O <final>) application-cursor/keypad table. Stateless by law:
     * the ESC O <letter> frames are unambiguous regardless of DECKPAM/DECARM.
     *
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html — SS3
     *      `ESC O p..y` frames and the VT100 keypad application-mode table.
     */
    private const SS3_KEY_MAP = [
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
        'M' => 'Enter',
        // Application-keypad mode (DECKPAM, `ESC =`) sends the whole numeric
        // keypad as SS3 letters: Op..Oy are digits 0..9, Ol the decimal
        // separator (labelled "COMMA on keypad" on the VT100, the modern
        // kp_decimal), Om minus.
        'p' => 'KP0',
        'q' => 'KP1',
        'r' => 'KP2',
        's' => 'KP3',
        't' => 'KP4',
        'u' => 'KP5',
        'v' => 'KP6',
        'w' => 'KP7',
        'x' => 'KP8',
        'y' => 'KP9',
        'l' => 'KPDecimal',
        'm' => 'KPSubtract',
    ];

    /** Bare arrows: final byte of a parameter-less CSI (e.g. ESC [ A). */
    private const ARROW_FINALS = ['A' => 'ArrowUp', 'B' => 'ArrowDown', 'C' => 'ArrowRight', 'D' => 'ArrowLeft'];

    /** Finals recognized in the xterm modified-key form `CSI 1 ; <mod> <final>`. */
    private const MODIFIED_KEY_MAP = [
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

    /** Legacy numbered special keys in the tilde family (`CSI <n> ~`). */
    private const TILDE_SPECIAL_KEYS = [
        1 => 'Home', 2 => 'Insert', 3 => 'Delete', 4 => 'End',
        5 => 'PageUp', 6 => 'PageDown',
        // rxvt/aterm extended the family: 7~/8~ are Home/End.
        7 => 'Home', 8 => 'End',
    ];

    /**
     * Legacy numbered function keys in the tilde family (terminfo
     * `kf1..kf24`-style numbering: 11..38 with the VT gaps; conventions vary
     * between terminfo databases). This numbering belongs
     * to the tilde space ONLY — CSI-u functional codes are the spec table
     * KITTY_FUNCTIONAL_KEYS below, not these numbers.
     */
    private const TILDE_FUNCTION_KEYS = [
        11 => 'F1', 12 => 'F2', 13 => 'F3', 14 => 'F4',
        15 => 'F5', 17 => 'F6', 18 => 'F7', 19 => 'F8',
        20 => 'F9', 21 => 'F10', 23 => 'F11', 24 => 'F12',
        25 => 'F13', 26 => 'F14', 28 => 'F15', 29 => 'F16',
        31 => 'F17', 32 => 'F18', 33 => 'F19', 34 => 'F20',
        35 => 'F21', 36 => 'F22', 37 => 'F23', 38 => 'F24',
    ];

    /** Remaining bytes after last decode() that couldn't form a complete sequence */
    private string $remainder = '';

    /** Paste accumulation buffer */
    private string $pasteBuffer = '';

    /** Whether we are currently inside a bracketed paste */
    private bool $inPaste = false;

    /** True while a paste body is being discarded after its one-time truncated announce */
    private bool $pasteOverflowDiscarding = false;

    /** True while bytes are swallowed until an overflowed sequence's final byte */
    private bool $discardUntilSequenceEnd = false;

    /** Paste-body bytes swallowed past the cap while awaiting the real 201~ terminator */
    private int $discardedPasteBytes = 0;

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

    /** Protocol toggles gating mouse / Kitty / focus / paste parsing. */
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
     * Per-call work is linear in the chunk length: no leg re-slices the
     * remaining stream per escape (see decodeClean's grammar-bounded
     * views), and hostile never-terminating strings are bounded by the
     * drain cap plus a one-shot counted abandon. Drivers are still the
     * recommended ceiling — they read ≤ 8192 bytes at a time — but a
     * caller feeding a giant blob pays proportional, not quadratic,
     * cost (SI-10).
     *
     * @param string $bytes Raw bytes from the terminal
     * @return list<Event>
     */
    public function decode(string $bytes): array
    {
        // An oversized sequence is still open: its bytes are parameter
        // traffic, never keystrokes — swallow them until its final byte or a
        // fresh ESC resync (C2a). This runs before everything so split-chunk
        // input decodes identically to one big read.
        if ($this->discardUntilSequenceEnd) {
            $bytes = $this->consumeOverflowedSequence($bytes);
        }

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

                // A paste-start marker is an external resynchronisation
                // boundary: decodeClean($prefix) may have re-buffered an
                // incomplete escape fragment (e.g. a stale "ESC [" from before
                // the paste) into $this->remainder, and that fragment must
                // never stitch onto the first post-paste keystroke — the same
                // drop-the-orphan law the stray paste-END handling follows.
                $this->remainder = '';

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

            // Escape character — hand a GRAMMAR-BOUNDED VIEW of the tail to the
            // (unchanged) string-based escape handlers, so one decode() call
            // costs O(bytes the sequence actually spans) — never O(the whole
            // in-hand chunk) per escape (SI-10: a 500 KB escape-dense chunk
            // used to reslice the whole remaining tail at every ESC). The view
            // ends at the first plausible sequence final, one lookahead byte
            // past an embedded ESC (the handlers' own ambiguity arms), or a
            // hard string-introducer budget; an incomplete sequence runs the
            // view to the stream end, where the view IS the tail and the old
            // stream-context rules (deferTrailingEscape, capped buffer) apply
            // unchanged. The advance is suffix-normalised: handleEscape's
            // `remaining` is always a suffix of the string it was handed, so
            // strlen(view) - strlen(remaining) is the exact consumed count for
            // either argument shape.
            if ($ord === 0x1b) {
                $viewLen = $this->escapeViewLength($stream, $i, $len);
                $view = substr($stream, $i, $viewLen);
                $result = $this->handleEscape($view);
                $progress = $result['events'] !== [] || $result['remaining'] !== $view;
                if (!$progress && $viewLen < $len - $i) {
                    // A bounded view refused to make progress while more bytes
                    // sit in hand: the handlers' "incomplete" decisions depend
                    // on stream end. Re-ask once with the full tail (rare — a
                    // real incomplete sequence); consumed positions agree for
                    // both views either way.
                    $view = substr($stream, $i);
                    $result = $this->handleEscape($view);
                }
                if ($result['events'] !== [] || $result['remaining'] !== $view) {
                    // Append per-event, not array_merge: merging into the
                    // growing list would re-copy it at every escape — the
                    // same amortization law decode()'s pass loop follows.
                    foreach ($result['events'] as $event) {
                        $events[] = $event;
                    }
                    $i += strlen($view) - strlen($result['remaining']);
                    if ($this->inString && $i < $len) {
                        // An OSC/DCS/SOS/PM/APC stayed open past the view:
                        // hand the LIVE remainder to decode()'s string state
                        // machine on the next call (its own chunked legs
                        // bound it) rather than slicing a fresh tail per
                        // call. This bypasses bufferRemainder's sequence cap
                        // deliberately — the string legs measure their own
                        // budget against it.
                        $this->remainder = substr($stream, $i);
                        return $events;
                    }
                    continue;
                }
                // Incomplete escape (no events, no progress): buffer the tail
                // (capped) for the next decode() call and stop. The view run
                // to the stream end here, so it IS the tail.
                $this->bufferRemainder($view);
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
            $seqLen = $this->utf8SeqLength($stream, $i);

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
     * UTF-8 expected-sequence-length classifier shared by both multibyte
     * walkers (decodeClean and the ESC+lead leg). Returns 2/3/4 for a valid
     * lead byte, 0 for anything that cannot start a well-formed codepoint:
     * 0x80-0xBF lone continuations, 0xC0-0xC1/0xF5-0xFF overlong/out-of-range
     * leads — and the second-byte audits: ED A0-BF (surrogate halves, m12),
     * E0 80-9F and F0 80-8F (overlong re-spellings of shorter codepoints),
     * F4 90-BF (past U+10FFFF). None of these can exist in well-formed UTF-8
     * (mbstring/json/MySQL reject them), so a stream carrying one must resync
     * byte-by-byte instead of surfacing a multi-byte key that every
     * downstream codec chokes on.
     */
    private function utf8SeqLength(string $stream, int $leadPos): int
    {
        $ord = ord($stream[$leadPos]);
        $seqLen = match (true) {
            $ord >= 0xc2 && $ord <= 0xdf => 2,
            $ord >= 0xe0 && $ord <= 0xef => 3,
            $ord >= 0xf0 && $ord <= 0xf4 => 4,
            default => 0,
        };

        // Second-byte audit: four lead families have restricted continuation
        // ranges because the rest of their space is illegal — ED A0-BF encodes
        // U+D800-DFFF surrogate halves (cannot exist in well-formed UTF-8),
        // E0 80-9F and F0 80-8F are overlong encodings (they re-spell
        // codepoints shorter sequences already own), and F4 90+ passes
        // U+10FFFF. mbstring/json/MySQL reject all of them, so a stream
        // carrying one must resync byte-by-byte instead of producing a
        // multi-byte key every downstream codec chokes on (m12). A not-yet
        // -visible second byte stays a hold-partial; the law re-checks when
        // the completion arrives.
        if ($leadPos + 1 < strlen($stream)) {
            $second = ord($stream[$leadPos + 1]);
            $refused = match (true) {
                $ord === 0xed => $second >= 0xa0 && $second <= 0xbf,
                $ord === 0xe0 => $second >= 0x80 && $second <= 0x9f,
                $ord === 0xf0 => $second >= 0x80 && $second <= 0x8f,
                $ord === 0xf4 => $second >= 0x90 && $second <= 0xbf,
                default => false,
            };
            if ($refused) {
                return 0;
            }
        }

        return $seqLen;
    }

    /**
     * Bound how far an escape at $i may reach into $stream, so the handler is
     * handed a view whose length is O(the sequence it actually contains) —
     * never the whole remaining chunk (SI-10: the per-escape full-tail slice
     * made one decode() call quadratic in the chunk length).
     *
     * The view is grammar-bounded, deliberately generous, and conservative:
     * a CSI/SS3 runs to its final byte (0x40-0x7e), stopping one lookahead
     * byte past any embedded ESC so the handlers' own ambiguity arms still
     * see what they need; a string introducer (] P _ ^) runs to its BEL or
     * ST terminator, or to the drain+abandon budget (the payload legs
     * measure against exactly that much); anything else (Alt+x, UTF-8) runs
     * a flat few bytes. If the scan runs off the END of the in-hand stream,
     * the returned length reaches the stream end — the view then IS the old
     * tail, so the handlers' incomplete-sequence decisions (defer the lone
     * trailing ESC, cap the buffered remainder) are made with the same
     * information as before this bound existed.
     */
    private function escapeViewLength(string $stream, int $i, int $len): int
    {
        if ($i + 1 >= $len) {
            return 1; // lone trailing ESC — the deferral rule wants exactly this
        }
        $introducer = $stream[$i + 1];

        if ($introducer === '[' || $introducer === 'O') {
            for ($k = $i + 2; $k < $len; $k++) {
                $ord = ord($stream[$k]);
                if ($ord === 0x1b) {
                    // embedded ESC: include it plus one lookahead byte, the
                    // most the handlers ever consult past the ambiguity point
                    return min($k + 2, $len) - $i;
                }
                if ($ord >= 0x40 && $ord <= 0x7e) {
                    return $k + 1 - $i; // final byte: the sequence can end here
                }
            }

            return $len - $i; // ran off the stream end: incomplete — full tail
        }

        if ($introducer === ']' || $introducer === 'P' || $introducer === '_' || $introducer === '^') {
            // String sequence: the payload legs bound themselves at
            // MAX_STRING_LENGTH + MAX_STRING_ABANDON; never hand them more.
            $cap = self::MAX_STRING_LENGTH + self::MAX_STRING_ABANDON + 1;
            $stop = min($len, $i + 2 + $cap);
            for ($k = $i + 2; $k < $stop; $k++) {
                if ($stream[$k] === "\x07") {
                    return $k + 1 - $i; // BEL terminator
                }
                if ($stream[$k] === "\x1b" && $k + 1 < $stop && $stream[$k + 1] === '\\') {
                    return $k + 2 - $i; // ST terminator
                }
            }

            return $stop - $i; // no terminator in reach: budget or stream end
        }

        return min(8, $len - $i); // Alt / ESC ESC / UTF-8 lead: a few bytes is all
    }

    /**
     * Buffer an incomplete trailing sequence for the next decode() call,
     * bounding $remainder so a never-terminating sequence cannot grow it
     * without limit (a DoS vector — see MAX_SEQUENCE_LENGTH). Oversized tails
     * park no bytes but keep the sequence OPEN in the discard state (see
     * consumeOverflowedSequence()); whatever events were already decoded are
     * still returned.
     */
    private function bufferRemainder(string $tail): void
    {
        if (strlen($tail) > self::MAX_SEQUENCE_LENGTH) {
            // Cap hit: park nothing — but the sequence is STILL open. Its
            // continuation bytes are parameter traffic, not keystrokes, so
            // record the drop once and swallow forward until the final byte
            // (C2a: wiping the buffer and carrying on let the leftover bytes
            // surface as synthetic keys in the next read).
            $this->remainder = '';
            $this->discardUntilSequenceEnd = true;
            $this->droppedUnknownCount++;

            return;
        }

        $this->remainder = $tail;
    }

    /**
     * While an oversized sequence is still open, consume stream bytes until its
     * ECMA-48 final byte (0x40-0x7E — consumed, the sequence ends there) or a
     * fresh ESC (NOT consumed — a new sequence begins). Returns the still-live
     * suffix; '' when the whole chunk belonged to the dead sequence.
     *
     * This makes a split hostile read decode IDENTICALLY to one big read.
     */
    private function consumeOverflowedSequence(string $stream): string
    {
        $len = strlen($stream);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($stream[$i]);
            if ($ord >= 0x40 && $ord <= 0x7E) {
                $this->discardUntilSequenceEnd = false;

                return substr($stream, $i + 1);
            }
            if ($ord === 0x1B) {
                // ESC inside parameter traffic is a parser desync a real
                // terminal can also hit: resynchronize on it (leave the ESC
                // for the escape handlers) instead of swallowing every later
                // keystroke forever.
                $this->discardUntilSequenceEnd = false;

                return substr($stream, $i);
            }
        }

        return '';
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

        // ESC <non-[> — Alt+key. When the byte after ESC is a UTF-8 lead,
        // consume the COMPLETE codepoint: bubbletea decodes the full rune
        // following ESC as one Alt+rune event. Slicing here (ESC + lead byte
        // only) would strand the continuation bytes, which the resync path in
        // decodeClean then reports as stray lone-byte keys (Alt+é arriving as
        // Alt+0xc3 + "\xa9").
        if ($nextOrd >= 0x80) {
            // Shared classifier: rejects surrogate-encoded ED A0-BF leads too,
            // so Alt+lead falls through to byte-wise resync like decodeClean.
            $seqLen = $this->utf8SeqLength($stream, 1);

            if ($seqLen !== 0) {
                if (strlen($stream) - 1 >= $seqLen) {
                    if ($this->allContinuationBytes($stream, 2, 1 + $seqLen)) {
                        $seq = substr($stream, 1, $seqLen);
                        return [
                            'events' => [new KeyEvent($seq, KeyModifier::alt(), "\x1b" . $seq)],
                            'remaining' => substr($stream, 1 + $seqLen),
                        ];
                    }
                    // A continuation slot is already invalid — emit Alt+lead
                    // alone and resync on the next byte, mirroring decodeClean's
                    // malformed-codepoint handling.
                } elseif ($this->allContinuationBytes($stream, 2, strlen($stream))) {
                    // Codepoint split across reads (ESC + lead [+ valid tail]
                    // at chunk end): report no progress so decodeClean buffers
                    // the whole tail; the next decode() completes the rune.
                    return ['events' => [], 'remaining' => $stream];
                }
                // Fall through: malformed — Alt+lead byte alone below.
            }
        }

        return [
            'events' => [new KeyEvent($this->mapChar($next), KeyModifier::alt(), "\x1b" . $next)],
            'remaining' => substr($stream, 2),
        ];
    }

    /**
     * Handle an SS3 (ESC O) sequence.
     *
     * Modified forms carry a parameter span before the final byte — xterm's
     * legacy modified-key shape `ESC O 1 ; <mod> <final>` (Shift+Left is
     * `ESC O 1;2D`). The span MUST be consumed through the final byte: the
     * old single-byte scan dropped only the digit and leaked the rest as
     * printable keys. Same resync-through-the-final discipline the X10-mouse
     * branch names and uses.
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

        $modifiers = KeyModifier::none();
        $lead = ord($afterO[0]);

        if ($lead >= 0x30 && $lead <= 0x39) {
            // Parameter span <num>[;<mod>], then the final byte must follow.
            if (preg_match('/^(\d+)(?:;(\d*))?/', $afterO, $pm) !== 1) {
                $this->droppedUnknownCount++;
                return ['events' => [], 'remaining' => substr($afterO, 1)];
            }
            $span = strlen($pm[0]);
            $runs = [$pm[1]];
            if ($span >= strlen($afterO) || (isset($pm[2]) && $pm[2] === '')) {
                // Final byte (or the modifier digit after ';') has not
                // arrived yet: buffer the WHOLE tail for the next decode()
                // call (a leading digit means no printable interpretation is
                // possible anyway).
                return ['events' => [], 'remaining' => "\x1bO" . $afterO];
            }
            if (isset($pm[2]) && $pm[2] !== '') {
                $runs[] = $pm[2];
            }
            if (!$this->validateDigits($runs)) {
                // Oversized/non-digit parameter span: one counted drop of the
                // digits seen so far, resync on the remainder.
                $this->droppedUnknownCount++;
                return ['events' => [], 'remaining' => substr($afterO, $span)];
            }
            // Modifier param is 1 + bitmask; absent is an explicit no-modifier
            // (";1" de-bases to 0 the same way fromXtermParam's callers rely on).
            if (isset($pm[2]) && $pm[2] !== '') {
                $modRaw = (int) $pm[2];
                if ($modRaw < 1) {
                    // ";0" would de-base to -1 and fabricate every bit (see
                    // the modifyOtherKeys guard) — refused, fail-fast.
                    $this->droppedUnknownCount++;
                    return ['events' => [], 'remaining' => substr($afterO, $span + 1)];
                }
                $modifiers = KeyModifier::fromXtermParam($modRaw);
            }
            $final = $afterO[$span];
        } else {
            $span = 0;
            $final = $afterO[0];
        }

        $ss3Map = self::SS3_KEY_MAP;

        if (!isset($ss3Map[$final])) {
            // Unknown final byte — the whole parameter+final span is ONE
            // counted drop (never a one-byte drop plus a leaked remainder).
            $this->droppedUnknownCount++;
            return ['events' => [], 'remaining' => substr($afterO, $span + 1)];
        }

        $raw = "\x1bO" . substr($afterO, 0, $span + 1);
        return [
            'events' => [new KeyEvent($ss3Map[$final], $modifiers, $raw)],
            'remaining' => substr($afterO, $span + 1),
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
     * Digit hygiene for every numeric escape-sequence field this decoder
     * casts: each element is one digit run (colon sub-parameters are split
     * by the caller — SGR 1016 carries pixel coordinates as "640:10"), and
     * every run must be 1..$maxRun digits (or, when $allowEmpty, the empty
     * string — an omitted parameter is a legal zero per the Kitty grammar).
     *
     * A run longer than $maxRun digits saturates an (int) cast toward
     * PHP_INT_MAX — synthetic extremes the wire never means — so it is
     * refused, never coerced. One helper gates the Kitty u-frame fields,
     * the SGR 1006/1016 runs and the urxvt 1015 runs: three callers, one
     * law, cannot drift.
     *
     * @param list<string> $runs Digit runs of the frame's numeric fields
     */
    private function validateDigits(array $runs, int $maxRun = 9, bool $allowEmpty = true): bool
    {
        $quantifier = $allowEmpty ? '0' : '1';
        $pattern = '/^\d{' . $quantifier . ',' . $maxRun . '}$/';
        foreach ($runs as $run) {
            if (preg_match($pattern, $run) !== 1) {
                return false;
            }
        }

        return true;
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
        // Every parameter must be numeric before any (int) cast, or attacker
        // bytes silently become coordinates: (int) 'x' is 0 and a forged frame
        // lands a MouseEvent at row/column zero. SGR 1016 legitimately carries
        // colon sub-parameters ("640:10" pixel x), so digit groups separated by
        // ':' are numeric too — the first group is the value, as before.
        if (!$this->validateDigits(array_merge(...array_map(fn(string $part): array => explode(':', $part), $parts)), 9, false)) {
            $this->droppedUnknownCount++;
            return ['events' => [], 'remaining' => $remaining];
        }
        $btnRaw = explode(':', $btnRaw)[0];
        $x = explode(':', $x)[0];
        $y = explode(':', $y)[0];
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
        // Payload bytes must sit in the printable 7-bit range 0x20..0x7E.
        // A control byte means the report was truncated — resync on it rather
        // than swallowing a fresh ESC sequence. A byte >= 0x80 is the UTF-8
        // mode 1005 coordinate extension: it is NOT modelled here, and the
        // old permissive range let those bytes through to ord-32, surfacing
        // forged high-button clicks (a 0xA0 lead is "button 128"). Refuse
        // both shapes with one counted drop.
        $refuse = static fn(int $ord): bool => $ord < 0x20 || $ord > 0x7F;

        if (strlen($afterM) < 3) {
            for ($j = 0; $j < strlen($afterM); $j++) {
                if ($refuse(ord($afterM[$j]))) {
                    $this->droppedUnknownCount++;
                    return ['events' => [], 'remaining' => substr($afterM, $j)];
                }
            }
            // Incomplete — wait for the rest of the payload.
            return ['events' => [], 'remaining' => "\x1b[M" . $afterM];
        }

        for ($j = 0; $j < 3; $j++) {
            if ($refuse(ord($afterM[$j]))) {
                $this->droppedUnknownCount++;
                return ['events' => [], 'remaining' => substr($afterM, $j)];
            }
        }

        if (!$this->options->enableMouse) {
            // Structurally consumed, no event — stream stays in sync. Tallied as a
            // counted drop like every other disabled family's frames (SGR falls to
            // the generic unknown-CSI drop, urxvt-1015 and Kitty gate to the same
            // counter), so droppedUnknownCount() stays an honest total.
            $this->droppedUnknownCount++;

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
     *  - bit 7 (value 128): the horizontal-wheel pair — 128 = scroll-left,
     *    129 = scroll-right (xterm's bit-7 fold of the 66/67 forms)
     *
     * A wheel code (64/65/66/67 or 128/129, optionally | 32 | modifiers) must
     * NEVER fall through to the plain-button path: `code & 3` of wheel-up (64)
     * is 0, and of 128/129 is 0/1, which used to surface a phantom left- or
     * right-click for every plain wheel event.
     *
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html — SGR mouse.
     */
    private function mouseFromButtonCode(int $code, int $x, int $y, bool $isReleaseChar): MouseEvent
    {
        $modifiers = KeyModifier::fromSgrMouse(($code >> 2) & 0x07);

        // xterm's bit-7 fold: 128/129 are the horizontal wheel pair sent in
        // place of the 66/67 bit-6 forms. Fold into the bit-6 family so the
        // same direction math classifies them — never a phantom button click.
        if (($code & 128) !== 0 && ($code & 64) === 0) {
            $code = ($code & ~128) | 64 | 2;
        }

        if (($code & 192) !== 0) {
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
     * Real Kitty frames carry NO private-mode "?" prefix — `CSI ? … u` is the
     * keyboard-flags QUERY/REPLY family and is drained as a TerminalReplyEvent
     * instead (see classifyPrivateReply). The old decoder had this inverted:
     * it parsed keys only behind "?" (so "\x1b[97;5u" produced nothing) and
     * mis-executed parameter consumption on "?" frames.
     *
     * Release is recognized both in the legacy encoding (modifiers OR 0x20) and
     * in the spec encoding (event-type sub-parameter 3; 1=press, 2=repeat).
     * Alternate key codes after the first colon in the code field are ignored —
     * the primary code identifies the key. Event type 2 (auto-repeat) is
     * surfaced as an ordinary press carrying {@see KeyEvent::$isRepeat}: a
     * repeat IS a keystroke for every TUI consumer of this decoder, and
     * inventing a "Repeat"-prefixed key name would widen the event vocabulary
     * for no gain — the annotation does not change the key name.
     *
     * @param string $seq  Complete CSI body ending in "u" (e.g. "97;5u", "97:65;2:3u")
     * @param string $rest Genuine suffix after the sequence
     * @return array{events: list<Event>, remaining: string}
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ — the CSI u encoding.
     */
    private function handleKittyEvent(string $seq, string $rest): array
    {
        // Isolate params (drop the final "u"), then the ';' fields with their
        // ':' sub-parameters. The grammar is `code[:alts] ; mods[:event-type]`
        // — a THIRD ';' field (e.g. "97;5;9u") is a malformed frame, counted
        // as a drop, never silently truncated into the modifier.
        $body = substr($seq, 0, -1);
        $fields = explode(';', $body);
        $codePart = explode(':', $fields[0]);
        $modPart = isset($fields[1]) ? explode(':', $fields[1]) : [];

        // Same digit law as the SGR/urxvt gates: every run of every field,
        // one helper, cannot drift. Empty runs are legal here (an omitted
        // parameter is zero); oversized or non-digit runs are refused.
        if (count($fields) > 2 || !$this->validateDigits(array_merge($codePart, $modPart))) {
            $this->droppedUnknownCount++;
            return ['events' => [], 'remaining' => $rest];
        }

        $codeRaw = $codePart[0] === '' ? 0 : (int) $codePart[0];

        $modRaw = 0;
        $eventType = 0;
        if ($modPart !== []) {
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

        $fullKey = $isRelease ? KeyEvent::RELEASE_PREFIX . ucfirst($keyName) : $keyName;
        $raw = "\x1b[" . $seq;

        return [
            'events' => [new KeyEvent($fullKey, $modifiers, $raw, $eventType === 2)],
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
            return ['events' => [new FocusEvent(true, "\x1b[" . $seq)], 'remaining' => $rest];
        }
        if ($this->options->enableFocus && $seq === 'O') {
            return ['events' => [new FocusEvent(false, "\x1b[" . $seq)], 'remaining' => $rest];
        }

        // Modified keys: CSI 1;<mod><final> (xterm format)
        // e.g., CSI 1;2A = Shift+ArrowUp, CSI 1;5C = Ctrl+ArrowRight
        // ("R" excluded: F3-with-modifier never wins against CPR ambiguity —
        // parameterized R is always drained as a cursor-position report above.)
        if (preg_match('/^(\d+)(?:;(\d+))?([A-DF-HJ-KP-T])$/', $seq, $m)) {
            $num = (int) $m[1];
            $modRaw = $m[2] === '' ? 1 : (int) $m[2];
            $finalKey = $m[3];

            // xterm modified keys carry num=1 and a modifier parameter; ";1"
            // (and the parameter-less "1<final>") are an explicit NO-modifier —
            // accepted, not refused (m6): the shape is unmistakable and decoding
            // it agrees with the unmodified CSI final anyway.
            if ($num === 1 && $modRaw >= 1) {
                $modifiers = KeyModifier::fromXtermParam($modRaw);

                $keyMap = self::MODIFIED_KEY_MAP;

                if (isset($keyMap[$finalKey])) {
                    return [
                        'events' => [new KeyEvent($keyMap[$finalKey], $modifiers, "\x1b[" . $seq)],
                        'remaining' => $rest,
                    ];
                }
            }
        }

        // Arrow keys: CSI A/B/C/D (a plain arrow has no params, so $seq is one byte)
        $arrowMap = self::ARROW_FINALS;
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

        // modifyOtherKeys report (xterm): CSI 27 ; <mods> ; <keysym> ~. When a key
        // that has no dedicated CSI sequence is pressed with modifiers under
        // modifyOtherKeys=1/2, xterm wraps it in this unmistakable "27;" form so a
        // plain 'a' and Ctrl+'a' never collide. The SET/RESET request is the
        // private "CSI > 4 ; level m" — a '>' frame drained as a terminal reply
        // above, never a keystroke. Not gated by enableKitty: this is the legacy
        // xterm extended-key family (final '~'), and the "27;n;n~" shape cannot be
        // produced by any ordinary keystroke, so decoding it is always safe.
        // @see xterm ctlseqs — "modifyOtherKeys".
        if (preg_match('/^27;(\d+);(\d+)~$/', $seq, $mk)) {
            $mods = (int) $mk[1];
            if ($mods < 1) {
                // The modifyOtherKeys modifier field is 1 + bitmask; 0 is malformed.
                // Passing 0 to fromXtermParam computes (0 - 1) = -1 and sets EVERY
                // bit, fabricating phantom Shift+Alt+Ctrl+Meta — reject it (fail fast)
                // the same way the Kitty path clamps its de-based field.
                return $this->dropUnknown($rest);
            }
            $moKeyName = $this->modifyOtherKeysName((int) $mk[2]);
            if ($moKeyName === null) {
                // Valid modifyOtherKeys frame carrying a keysym we neither name nor
                // print — consume it rather than leak the bytes.
                return $this->dropUnknown($rest);
            }

            return [
                'events' => [new KeyEvent($moKeyName, KeyModifier::fromXtermParam($mods), "\x1b[" . $seq)],
                'remaining' => $rest,
            ];
        }

        // Numbered function keys and special keys — plain (`15~`) and the
        // MODIFIED xterm form `CSI number ; modifier ~` (`3;5~` Ctrl+Delete,
        // `21;5~` Ctrl+F10, `3;2~` Shift+Delete). The two-parameter shape is
        // terminfo-standard, not invented: the xterm-256color and alacritty
        // entries ship kDC=\E[3;2~, and wezterm ships kf34=\E[21;5~. The form is
        // unmistakable at this point in the dispatch: the modifyOtherKeys wrap
        // (`27;mods;keysym ~`) has THREE params and is decoded above, and a
        // number absent from both tables (a stray `200~`/`201~` paste marker
        // with a modifier riding along) still falls through to the counted drop.
        // @see ECMA-48 — CSI parameter bytes 0x30-0x3F, one final byte 0x40-0x7E.
        // @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html —
        //      modified function keys under `modifyFunctionKeys` ("CSI number ;
        //      modifier ~" for the legacy numbered-key family).
        if (preg_match('/^(\d+)(?:;(\d+))?~$/', $seq, $m)) {
            $num = (int) $m[1];

            // The modifier field is 1 + the bitmask (same wire law as the
            // `1;mod<final>` arrow branch above). ";1" is an explicit
            // no-modifier and decodes bare; ";0" would de-base to -1 and
            // fabricate every bit (see the modifyOtherKeys guard) — refused,
            // fail-fast, as a counted drop.
            $modifiers = KeyModifier::none();
            if (isset($m[2]) && $m[2] !== '') {
                $modRaw = (int) $m[2];
                if ($modRaw < 1) {
                    return $this->dropUnknown($rest);
                }
                $modifiers = KeyModifier::fromXtermParam($modRaw);
            }

            $specialKeys = self::TILDE_SPECIAL_KEYS;

            if (isset($specialKeys[$num])) {
                return [
                    'events' => [new KeyEvent($specialKeys[$num], $modifiers, "\x1b[" . $seq)],
                    'remaining' => $rest,
                ];
            }

            // F1-F4 via 11~-14~ (some terminals); F5+ via 15~ onward
            $fKeys = self::TILDE_FUNCTION_KEYS;

            if (isset($fKeys[$num])) {
                return [
                    'events' => [new KeyEvent($fKeys[$num], $modifiers, "\x1b[" . $seq)],
                    'remaining' => $rest,
                ];
            }
        }

        // Mouse 1015 (urxvt) report: CSI <b> ; <x> ; <y> M — three decimal
        // parameters and a final byte that is ALWAYS 'M'. It is distinguished from
        // the SGR 1006/1016 path solely by the absence of the '<' introducer
        // (handleCSI routes '<'-led reports to handleSgrMouse before ever reaching
        // here), so the two encodings never collide. Like X10 there is no lowercase
        // 'm': release is signalled by the button code's low bits (== 3).
        // @see xterm ctlseqs — mode 1015 ("urxvt"-style extended mouse).
        if ($this->options->enableMouse && $final === 'M') {
            return $this->handleUrxvtMouse($seq, $rest);
        }

        // Complete but unrecognized CSI — consume it, emit nothing, and return
        // the genuine suffix so trailing bytes in the same chunk are preserved.
        return $this->dropUnknown($rest);
    }

    /**
     * Decode a urxvt mouse-1015 report body: "<b>;<x>;<y>M" (already split to its
     * grammar boundary by splitCsi(), so no '<' and a guaranteed trailing 'M').
     *
     * Only the exact three-decimal-parameter shape is a mouse report; anything
     * else ending in 'M' (e.g. SU "CSI Ps M", one parameter) falls through to the
     * generic unknown-CSI drop.
     *
     * @param string $seq  Complete CSI body ending in 'M'
     * @param string $rest Genuine suffix after the sequence
     * @return array{events: list<Event>, remaining: string}
     * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html — mode 1015.
     */
    private function handleUrxvtMouse(string $seq, string $rest): array
    {
        $parts = explode(';', substr($seq, 0, -1));
        if (count($parts) !== 3 || !$this->validateDigits($parts, 9, false)) {
            return $this->dropUnknown($rest);
        }

        // rxvt-unicode and xterm (mode 1015) print the X10 button code biased by 32
        // — left press 32, release 35, drag 64, wheel-up 96 — so a field at or above
        // 32 is de-biased back into the 0..127 X10 space mouseFromButtonCode expects.
        // A field below 32 can only be an already-raw code (the biased form's minimum
        // is 32), which some hosts/tests emit; it is passed through unchanged. The one
        // value the two conventions cannot distinguish is a raw wheel/drag code sent
        // UNbiased (>= 64 with no +32) — not produced by rxvt/xterm — which would read
        // as a lower button; the biased reading is the intended one for real terminals.
        $code = (int) $parts[0];
        if ($code >= 32) {
            $code -= 32;
        }

        return [
            'events' => [$this->mouseFromButtonCode($code, (int) $parts[1], (int) $parts[2], false)],
            'remaining' => $rest,
        ];
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

            // Not complete — accumulate (unless the paste is already past its
            // cap and discarding: those bytes belong to the announced event).
            if ($stream !== '') {
                if ($this->pasteOverflowDiscarding) {
                    $this->discardedPasteBytes += strlen($stream);

                    return ['events' => [], 'remaining' => ''];
                }
                $this->pasteBuffer .= $stream;
            }
            if (!$this->pasteOverflowDiscarding && strlen($this->pasteBuffer) > PasteEvent::MAX_SIZE) {
                // Cap hit: announce ONCE with the truncated PasteEvent, but do
                // NOT close the paste (C2b). Closing let every byte that
                // followed — through the real 201~ terminator — decode as
                // synthetic keystrokes: attacker-controlled pipe content was
                // promoted to key traffic, the exact tmux send-keys shape the
                // README threat model names. The paste stays open and discards
                // body bytes until the genuine end marker arrives; reset() is
                // the recovery lever if a forged paste never sends one. Leave
                // any held-back partial end marker in $remainder untouched —
                // wiping it would turn the marker split across the overflow
                // boundary into literal keys.
                $event = PasteEvent::truncate($this->pasteBuffer);
                $this->discardedPasteBytes += strlen($this->pasteBuffer) - PasteEvent::MAX_SIZE;
                $this->pasteBuffer = '';
                $this->pasteOverflowDiscarding = true;

                return ['events' => [$event], 'remaining' => ''];
            }

            return ['events' => [], 'remaining' => ''];
        }

        if ($this->pasteOverflowDiscarding) {
            // Terminator found while discarding: close quietly — the truncate
            // announce already told the whole truth, and a second event would
            // hand the attacker a keystroke-shaped echo of their own tail.
            $this->discardedPasteBytes += $pasteEndPos;
            $this->pasteBuffer = '';
            $this->inPaste = false;
            $this->pasteOverflowDiscarding = false;

            return [
                'events' => [],
                'remaining' => substr($stream, $pasteEndPos + strlen(self::PASTE_END)),
            ];
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
                // Same one-time-announce + keep-discarding law as
                // handlePastePass() (C2b); a held-back partial end marker in
                // $remainder survives untouched.
                $event = PasteEvent::truncate($this->pasteBuffer);
                $this->discardedPasteBytes += strlen($this->pasteBuffer) - PasteEvent::MAX_SIZE;
                $this->pasteBuffer = '';
                $this->pasteOverflowDiscarding = true;

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
     * echo of a query) — never a keystroke. Families per xterm ctlseqs / Kitty
     * keyboard protocol:
     *  - final "c"   → DA1 (`CSI ? Ps ; Ps c`) / DA2 (`CSI > Ps ; Ps c`) reply
     *  - final "u"   → Kitty keyboard-flags reply (`CSI ? flags u`); the bare
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
                    // keystrokes forever — abandon and resync. The in-hand body
                    // past the boundary is REFUSED as one counted drop rather
                    // than replayed as tens of thousands of printable keys
                    // (SI-10 / FUZZ-05: the replay made a single hostile read
                    // cost unbounded events; bytes arriving in later reads are
                    // ordinary post-resync keystrokes).
                    $this->inString = false;
                    $this->stringOverflow = false;
                    $this->stringBuffer = '';
                    $this->stringDiscarded = 0;
                    $this->droppedUnknownCount++;

                    return ['events' => [], 'remaining' => ''];
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
                    // up exactly as the incremental path would: the in-hand
                    // excess is refused as one counted drop, never replayed as
                    // a key flood (SI-10 / FUZZ-05).
                    $this->inString = false;
                    $this->stringOverflow = false;
                    $this->stringDiscarded = 0;
                    $this->droppedUnknownCount++;

                    return ['events' => [$event], 'remaining' => ''];
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

        // M12: Alt-modified presses of the three symbolic control keys carry
        // the SAME names as their unmodified forms (Tab via decodeClean's
        // sibling, Enter via decodeControlChar, Backspace via decodeClean).
        // Without this, ESC+CR surfaced key="\r" while a bare CR surfaced
        // key='Enter' — one wire family, two vocabularies.
        if ($ord === 0x09) {
            return 'Tab';
        }
        if ($ord === 0x0a || $ord === 0x0d) {
            return 'Enter';
        }
        if ($ord === 0x7f) {
            return 'Backspace';
        }
        // 0x08 is the Ctrl-carrier lineage of Backspace (BS = Ctrl+H). On the
        // bare wire it pairs the letter 'h' with Ctrl; the ESC-prefixed form
        // carries no Ctrl bit, and terminals send ESC+0x08 for Alt+BS — it
        // joins the 0x7f leg's harmonized name so both lineages of one key
        // family answer with the same roster word (SI-21, m12 law).
        if ($ord === 0x08) {
            return 'Backspace';
        }

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
     *
     * Receives only bytes in 0x00-0x1f EXCEPT 0x1b: decodeClean() dispatches
     * every ESC byte through handleEscape() first (lone-ESC vs sequence
     * disambiguation lives there), so this method never sees one.
     */
    private function decodeControlChar(string $byte): KeyEvent
    {
        $ord = ord($byte);

        if ($ord === 0x09) return new KeyEvent('Tab', KeyModifier::none(), "\t");
        if ($ord === 0x0a || $ord === 0x0d) return new KeyEvent('Enter', KeyModifier::none(), $byte);

        // NUL (0x00): keyboards that produce it produce it as Ctrl+Space (the
        // layout's Ctrl+@ twin reaches the same byte). The wire byte has no
        // letter to pair, so the conventional label IS the name: `Space`
        // under Ctrl. The raw byte stays visible in `raw`.
        if ($ord === 0x00) {
            return new KeyEvent('Space', KeyModifier::ctrl(), $byte);
        }

        // Ctrl + carrier character. 0x01-0x1a pair the lowercase letters
        // (chr(byte + 0x60)); 0x1C-0x1F (FS/GS/RS/US) pair their punctuation
        // twins from the 0x40 block (chr(byte + 0x40): 0x1C = '\', 0x1D = ']',
        // 0x1E = '^', 0x1F = '_') — both legs are the same ASCII law, the
        // character whose code ANDs down to the byte, and both are what a US
        // layout actually reaches with Ctrl. One vocabulary: no key in
        // 0x00-0x1f reaches a host as a raw control byte.
        if ($ord >= 0x01 && $ord <= 0x1a) {
            return new KeyEvent(chr($ord + 0x60), KeyModifier::ctrl(), $byte);
        }
        if ($ord >= 0x1c && $ord <= 0x1f) {
            return new KeyEvent(chr($ord + 0x40), KeyModifier::ctrl(), $byte);
        }

        // The match above covers 0x00-0x1f except 0x1b, which decodeClean()
        // routes through handleEscape() before this method is reachable.
        // Landing here means that dispatch broke.
        throw new \LogicException(
            'decodeControlChar received 0x' . dechex($ord) . ' — only 0x00-0x1f minus ESC may reach it'
        );
    }

    /**
     * Kitty keyboard protocol functional key codes (U+E000 and above), verbatim
     * from the spec's functional-key table — the same partition sugar-core's
     * KeyType::kittyFunctionalKeys() carries, cross-checked by
     * tests/KeySpecTableParityTest.
     *
     * Historical defect this table cures: the decoder used to INVENT arrows at
     * 57399-57402 (the spec's Kp0..Kp3) and to borrow xterm's *tilde* numbering
     * (1..6 / 11..34) into CSI-u space. In the CSI-u space, codes below 0xE000
     * are TEXT codepoints only — a functional key never travels as 11 or 1;
     * those forms now count as drops instead of mis-naming printable ASCII
     * (11 is literally the control-K byte). Names use this lib's house
     * vocabulary (ArrowUp, KP0, KPDecimal — not the spec's KpUp spellings) so
     * Kitty frames and their SS3/CSI equivalents decode to one name.
     *
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ — functional key codes.
     */
    private const KITTY_FUNCTIONAL_KEYS = [
        57344 => 'Escape',
        57345 => 'Enter',
        57346 => 'Tab',
        57347 => 'Backspace',
        57348 => 'Insert',
        57349 => 'Delete',
        57350 => 'ArrowLeft',
        57351 => 'ArrowRight',
        57352 => 'ArrowUp',
        57353 => 'ArrowDown',
        57354 => 'PageUp',
        57355 => 'PageDown',
        57356 => 'Home',
        57357 => 'End',
        57358 => 'CapsLock',
        57359 => 'ScrollLock',
        57360 => 'NumLock',
        57361 => 'PrintScreen',
        57362 => 'Pause',
        57363 => 'Menu',
        57364 => 'F1',
        57365 => 'F2',
        57366 => 'F3',
        57367 => 'F4',
        57368 => 'F5',
        57369 => 'F6',
        57370 => 'F7',
        57371 => 'F8',
        57372 => 'F9',
        57373 => 'F10',
        57374 => 'F11',
        57375 => 'F12',
        57376 => 'F13',
        57377 => 'F14',
        57378 => 'F15',
        57379 => 'F16',
        57380 => 'F17',
        57381 => 'F18',
        57382 => 'F19',
        57383 => 'F20',
        57384 => 'F21',
        57385 => 'F22',
        57386 => 'F23',
        57387 => 'F24',
        57388 => 'F25',
        57389 => 'F26',
        57390 => 'F27',
        57391 => 'F28',
        57392 => 'F29',
        57393 => 'F30',
        57394 => 'F31',
        57395 => 'F32',
        57396 => 'F33',
        57397 => 'F34',
        57398 => 'F35',
        57399 => 'KP0',
        57400 => 'KP1',
        57401 => 'KP2',
        57402 => 'KP3',
        57403 => 'KP4',
        57404 => 'KP5',
        57405 => 'KP6',
        57406 => 'KP7',
        57407 => 'KP8',
        57408 => 'KP9',
        57409 => 'KPDecimal',
        57410 => 'KPDivide',
        57411 => 'KPMultiply',
        57412 => 'KPSubtract',
        57413 => 'KPAdd',
        57414 => 'KPEnter',
        57415 => 'KPEqual',
        57416 => 'KPSeparator',
        57417 => 'KPLeft',
        57418 => 'KPRight',
        57419 => 'KPUp',
        57420 => 'KPDown',
        57421 => 'KPPageUp',
        57422 => 'KPPageDown',
        57423 => 'KPHome',
        57424 => 'KPEnd',
        57425 => 'KPInsert',
        57426 => 'KPDelete',
        57427 => 'KPBegin',
        57428 => 'MediaPlay',
        57429 => 'MediaPause',
        57430 => 'MediaPlayPause',
        57431 => 'MediaReverse',
        57432 => 'MediaStop',
        57433 => 'MediaFastForward',
        57434 => 'MediaRewind',
        57435 => 'MediaNext',
        57436 => 'MediaPrev',
        57437 => 'MediaRecord',
        57438 => 'LowerVolume',
        57439 => 'RaiseVolume',
        57440 => 'MuteVolume',
        57441 => 'LeftShift',
        57442 => 'LeftCtrl',
        57443 => 'LeftAlt',
        57444 => 'LeftSuper',
        57445 => 'LeftHyper',
        57446 => 'LeftMeta',
        57447 => 'RightShift',
        57448 => 'RightCtrl',
        57449 => 'RightAlt',
        57450 => 'RightSuper',
        57451 => 'RightHyper',
        57452 => 'RightMeta',
        57453 => 'IsoLevel3Shift',
        57454 => 'IsoLevel5Shift',
    ];

    /**
     * Map a Kitty key code to a symbolic key name.
     *
     * Codes below the functional table's 0xE000 start are TEXT codepoints:
     * the four shared control codes and space keep their symbolic names, the
     * rest of 32–0xD7FF arrives as the character itself, and the surrogate
     * block plus the unnamed controls below 32 are unmapped — the caller
     * counts those drops. Functional keys live exclusively at 0xE000+ per the
     * spec table hoisted above.
     *
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ — the CSI u encoding.
     */
    private function kittyKeyCodeToName(int $code): string|null
    {
        // Tab, Enter, Escape, Backspace travel as their ASCII values.
        if ($code === 9)  return 'Tab';
        if ($code === 13) return 'Enter';
        if ($code === 27) return 'Escape';
        if ($code === 127) return 'Backspace';

        // Space
        if ($code === 32) return 'Space';

        // Letters (Kitty reports the shifted codepoint; we name the letter)
        if ($code >= 97 && $code <= 122) return chr($code);
        if ($code >= 65 && $code <= 90)  return chr($code + 32);

        // Remaining TEXT codepoints arrive as the character itself — digits,
        // punctuation and Unicode runes below the spec's functional space.
        // Control-adjacent codes (< 32) were named above or stay counted drops,
        // and the surrogate block (0xD800–0xDFFF) is not a scalar value, so the
        // leg ends at 0xD7FF and those codes keep counting as drops — exactly
        // the contract the modifyOtherKeys leg honors for printable keysyms.
        if ($code >= 32 && $code <= 0xD7FF) return mb_chr($code);

        return self::KITTY_FUNCTIONAL_KEYS[$code] ?? null;
    }

    /**
     * Resolve a modifyOtherKeys `keysym` field to a key name.
     *
     * The `CSI 27 ; <mods> ; <keysym> ~` report carries an X11 keysym, NOT a Kitty
     * functional codepoint, so it must not be fed to kittyKeyCodeToName() blindly:
     * keysyms below 0xE000 are character codes, and passing them through the text
     * leg would name control-adjacent keysyms wrong. Printable ASCII (32..126) is
     * reported as the literal character so typing '!', '"' or the space
     * character (U+0020) under modifyOtherKeys=2 still reaches the app; the
     * shared control keysyms keep their symbolic names; only the modern Kitty
     * functional range (>= 0xE000, arrows etc.) falls back to the shared
     * table. Anything else is unnamed -> null.
     *
     * @see xterm ctlseqs — "modifyOtherKeys".
     * @see X11 keysym definitions (<X11/keysymdef.h>: XK_exclam = 0x21 = 33).
     */
    /**
     * X11 function-keysym block (0xFF00-0xFFFF) names for modifyOtherKeys
     * `CSI 27 ; <mods> ; <keysym> ~` frames. Only keysyms this lib already
     * has a house name for appear: function keys F1-F24 (0xFFBE-0xFFD5),
     * navigation (Home/End/Prior/Next/arrows/Insert), the specials
     * Tab/Enter/Backspace/Escape, and the keypad block (0xFF8D-0xFFBD) onto
     * the KP* vocabulary. Unassigned or unhoused keysyms (Select 0xFF60,
     * Pause 0xFF13, KP_Space, …) fall through to the honest counted drop —
     * mapping them would invent wire names the rest of the lib never emits.
     * One keysymdef.h member is deliberately EXCLUDED: Delete (0xFFFF) is
     * the lib's long-pinned "unknown keysym drains" witness frame
     * (testModifyOtherKeysUnknownKeysymConsumed), so it keeps dropping.
     */
    private const X11_KEYSYM_MAP = [
        0xFF08 => 'Backspace',
        0xFF09 => 'Tab',
        0xFF0D => 'Enter',
        0xFF1B => 'Escape',
        0xFF50 => 'Home',
        0xFF51 => 'ArrowLeft',
        0xFF52 => 'ArrowUp',
        0xFF53 => 'ArrowRight',
        0xFF54 => 'ArrowDown',
        0xFF55 => 'PageUp',
        0xFF56 => 'PageDown',
        0xFF57 => 'End',
        0xFF63 => 'Insert',
        0xFFBE => 'F1', 0xFFBF => 'F2', 0xFFC0 => 'F3', 0xFFC1 => 'F4',
        0xFFC2 => 'F5', 0xFFC3 => 'F6', 0xFFC4 => 'F7', 0xFFC5 => 'F8',
        0xFFC6 => 'F9', 0xFFC7 => 'F10', 0xFFC8 => 'F11', 0xFFC9 => 'F12',
        0xFFCA => 'F13', 0xFFCB => 'F14', 0xFFCC => 'F15', 0xFFCD => 'F16',
        0xFFCE => 'F17', 0xFFCF => 'F18', 0xFFD0 => 'F19', 0xFFD1 => 'F20',
        0xFFD2 => 'F21', 0xFFD3 => 'F22', 0xFFD4 => 'F23', 0xFFD5 => 'F24',
        0xFF8D => 'KPEnter',
        0xFF95 => 'KPHome',
        0xFF96 => 'KPLeft',
        0xFF97 => 'KPUp',
        0xFF98 => 'KPRight',
        0xFF99 => 'KPDown',
        0xFF9A => 'KPPageUp',
        0xFF9B => 'KPPageDown',
        0xFF9C => 'KPEnd',
        0xFF9D => 'KPBegin',
        0xFF9E => 'KPInsert',
        0xFF9F => 'KPDelete',
        0xFFAA => 'KPMultiply',
        0xFFAB => 'KPAdd',
        0xFFAC => 'KPSeparator',
        0xFFAD => 'KPSubtract',
        0xFFAE => 'KPDecimal',
        0xFFAF => 'KPDivide',
        0xFFB0 => 'KP0', 0xFFB1 => 'KP1', 0xFFB2 => 'KP2', 0xFFB3 => 'KP3',
        0xFFB4 => 'KP4', 0xFFB5 => 'KP5', 0xFFB6 => 'KP6', 0xFFB7 => 'KP7',
        0xFFB8 => 'KP8', 0xFFB9 => 'KP9',
        0xFFBD => 'KPEqual',
    ];

    private function modifyOtherKeysName(int $keysym): string|null
    {
        if ($keysym >= 32 && $keysym <= 126) {
            return chr($keysym);
        }

        if ($keysym >= 0xFF00 && $keysym <= 0xFFFF) {
            // X11 function-keysym block — consulted BEFORE the Kitty-leg
            // fallthrough (0xFF00 numerically outranks 0xE000) and before the
            // unknown-drop, so Shift+Home (`27;2;65360~`) is a named event.
            return self::X11_KEYSYM_MAP[$keysym] ?? null;
        }

        if ($keysym >= 0xe000) {
            return $this->kittyKeyCodeToName($keysym);
        }

        return match ($keysym) {
            9   => 'Tab',
            13  => 'Enter',
            27  => 'Escape',
            127 => 'Backspace',
            default => null,
        };
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
     * event (unknown CSI finals, malformed runs, unknown Kitty key codes).
     */
    public function droppedUnknownCount(): int
    {
        return $this->droppedUnknownCount;
    }

    /**
     * Bytes of paste body swallowed past the 1 MiB cap while waiting for the
     * paste's real 201~ terminator (the truncated PasteEvent announce fires
     * once, at the cap). A forged paste that never sends the terminator keeps
     * discarding — this counter grows monotonically and is the visible proof
     * the tail became nothing rather than keys. reset() clears it along with
     * the state.
     */
    public function discardedPasteBytes(): int
    {
        return $this->discardedPasteBytes;
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
     * Clear the partial-sequence buffer, paste/string state, overflow-discard
     * states, and reply counters. This is the operator's recovery lever for a
     * forged paste whose 201~ terminator never arrives (the decoder otherwise
     * keeps discarding, by design, so no attacker byte can become a key).
     */
    public function reset(): void
    {
        $this->remainder = '';
        $this->pasteBuffer = '';
        $this->inPaste = false;
        $this->discardUntilSequenceEnd = false;
        $this->pasteOverflowDiscarding = false;
        $this->discardedPasteBytes = 0;
        $this->inString = false;
        $this->stringIntroducer = '';
        $this->stringBuffer = '';
        $this->stringOverflow = false;
        $this->stringDiscarded = 0;
        $this->drainedReplyCount = 0;
        $this->droppedUnknownCount = 0;
    }
}

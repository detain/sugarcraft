<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\RawMode;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Tiny terminal picker — renders a numbered list of {@see Endpoint}s,
 * lets the user move with j/k or ↑/↓, narrow with type-to-search,
 * and pick with Enter. Returns the selected Endpoint or null on
 * Esc / Ctrl-C.
 *
 * Why not the full SugarBits `List` widget? That's overkill for a
 * one-shot picker that we immediately replace with `pcntl_exec`.
 * This class only needs to draw a static list, read a few keys,
 * and exit fast — no event loop, no full Program lifecycle.
 *
 * Test seam: the streams are constructor arguments and tests subclass
 * this class overriding {@see setRawMode()} — there is no
 * `inputStream()` / `outputStream()` to override.
 */
class Picker
{
    /** @var resource */
    protected $in;
    /** @var resource */
    protected $out;
    private string $filter = '';
    private int $cursor = 0;
    private SmithWatermanMatcher $matcher;

    /**
     * @param resource|null $in
     * @param resource|null $out
     */
    public function __construct($in = null, $out = null)
    {
        $this->in  = $in  ?? STDIN;
        $this->out = $out ?? STDOUT;
        $this->matcher = new SmithWatermanMatcher();
    }

    /**
     * @param list<Endpoint> $endpoints
     */
    public function pick(array $endpoints): ?Endpoint
    {
        if ($endpoints === []) {
            return null;
        }
        $this->setRawMode(true);
        try {
            while (true) {
                $matches = $this->filterMatches($endpoints);
                if ($this->cursor >= count($matches)) {
                    $this->cursor = max(0, count($matches) - 1);
                }
                $this->draw($matches);

                $key = $this->readKey();
                switch ($key) {
                    case "\x03": /* ^C */
                    case "\x1b": /* ESC */
                        return null;
                    case "\r":
                    case "\n":
                        if ($matches === []) {
                            continue 2;
                        }
                        return $matches[$this->cursor]['endpoint'];
                    case 'j':
                    case "\x1b[B":
                        if ($this->cursor < count($matches) - 1) {
                            $this->cursor++;
                        }
                        break;
                    case 'k':
                    case "\x1b[A":
                        if ($this->cursor > 0) {
                            $this->cursor--;
                        }
                        break;
                    case "\x7f": /* backspace */
                    case "\x08":
                        $before = $this->filter;
                        // Codepoint-granular trim. readKey() delivers whole
                        // UTF-8 codepoints, and the matcher/highlighter index
                        // the filter by codepoint too (candy-fuzzy CharFold,
                        // see highlightLine()), so the delete unit must be the
                        // codepoint. A byte-wise substr here stranded dangling
                        // continuation bytes mid-character and echoed invalid
                        // UTF-8 to the terminal (mirrors candy-hermit
                        // 530c750f1). Trade-off stated honestly, same as
                        // there: a multi-codepoint grapheme such as '👍🏽'
                        // takes two backspaces to clear, because the skin-tone
                        // modifier is its own codepoint.
                        $this->filter = mb_substr($this->filter, 0, -1, 'UTF-8');
                        // Only reset cursor if the filter actually changed —
                        // on an empty filter, backspace is a true no-op and
                        // the current selection should be preserved.
                        if ($this->filter !== $before) {
                            $this->cursor = 0;
                        }
                        break;
                    default:
                        // Text intake. readKey() delivers whole UTF-8
                        // codepoints for typed characters and complete
                        // escape sequences (CSI / SS3 / Meta chords) for key
                        // events. Only control-free, well-formed text reaches
                        // the filter — ESC-bearing chords like "\x1bc" (RIS)
                        // or "\x1bp" (Alt+p) are consumed as opaque key
                        // events here and never accumulate as text.
                        if ($this->isTextKey($key)) {
                            $this->filter .= $key;
                            $this->cursor = 0;
                        }
                }
            }
        } finally {
            $this->setRawMode(false);
            fwrite($this->out, "\n");
        }
    }

    /**
     * @param list<Endpoint> $endpoints
     * @return list<array{endpoint: Endpoint, result: ?MatchResult, displayLine: string}>
     */
    private function filterMatches(array $endpoints): array
    {
        if ($this->filter === '') {
            return array_map(
                fn(Endpoint $e) => [
                    'endpoint' => $e,
                    'result' => null,
                    'displayLine' => $this->stripControls($e->displayLine()),
                ],
                $endpoints
            );
        }

        // Build sanitized display strings for scoring and rendering.
        // Using the same string for both ensures matched indices align
        // with what highlightLine() renders (no re-match needed).
        $displayLines = array_map(
            fn(Endpoint $e) => $this->stripControls($e->displayLine()),
            $endpoints
        );

        // Score each candidate; SmithWatermanMatcher returns null for no match
        $results = $this->matcher->matchAll($this->filter, $displayLines);

        // Build a map from haystack string to MatchResult for quick lookup
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r->haystack] = $r;
        }

        // Walk endpoints in original order, attaching MatchResult and displayLine
        $out = [];
        foreach ($endpoints as $idx => $e) {
            $sanitizedDisplay = $displayLines[$idx];
            $out[] = [
                'endpoint' => $e,
                'result' => $resultMap[$sanitizedDisplay] ?? null,
                'displayLine' => $sanitizedDisplay,
            ];
        }

        // Filter to only matched (score > 0) and sort by score descending
        $matched = array_filter($out, static fn(array $r) => $r['result'] !== null);
        usort($matched, static fn(array $a, array $b) =>
            $b['result']->score <=> $a['result']->score
        );

        return $matched;
    }

    /**
     * @param list<array{endpoint: Endpoint, result: ?MatchResult, displayLine: string}> $matches
     */
    private function draw(array $matches): void
    {
        fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseToEnd());
        fwrite($this->out, "── wishlist ──\r\n");
        // Belt-and-braces: intake (isTextKey) already refuses control bytes,
        // but the echo passes through the same sanitizer the config-derived
        // strings use, so no raw ESC can reach the terminal from here either.
        fwrite($this->out, 'filter: ' . Ansi::sgr(36) . $this->stripControls($this->filter) . Ansi::reset() . "\r\n");
        if ($matches === []) {
            fwrite($this->out, "  (no matches)\r\n");
        }
        foreach ($matches as $i => $record) {
            $e = $record['endpoint'];
            $marker = $i === $this->cursor ? Ansi::sgr(1, 36) . '▸' . Ansi::reset() . ' ' : '  ';
            // The displayLine is already sanitized from filterMatches()
            $line = $this->highlightLine($record['displayLine'], $record['result']);
            if ($e->description !== null && $e->description !== '') {
                $line .= '  ' . Ansi::sgr(Ansi::FAINT) . $this->stripControls($e->description) . Ansi::reset();
            }
            fwrite($this->out, "{$marker}{$line}\r\n");
        }
        fwrite($this->out, "\r\n  ↑/↓ select · Enter connect · Esc quit · type to filter\r\n");
    }

    /**
     * Strip ANSI escape sequences and C0 control characters from a string
     * before writing it to the terminal. This prevents malicious config
     * values (names, descriptions) from injecting escape sequences.
     */
    private function stripControls(string $s): string
    {
        // Ansi::strip() removes every 7-bit and 8-bit escape sequence,
        // including DCS/APC/PM/SOS string payloads (ANSI audit #9).
        $s = Ansi::strip($s);
        // Remove remaining C0 control characters except CR/LF (which the
        // picker handles as line terminators). This catches BEL, STX, etc.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $s);
    }

    /**
     * Apply bold+cyan highlighting to matched character positions in a display line.
     *
     * The $line argument is the sanitized display string; $result contains
     * matchedIndices computed against that same string in filterMatches().
     * We walk CODEPOINTS — the same index domain candy-fuzzy scores in
     * (CharFold::foldSplit is 1:1 with original codepoints, see
     * SmithWatermanMatcher), so matched indices land on the characters the
     * matcher actually aligned. A grapheme walk here (the old /\X/u) would
     * desynchronise every index after a combining mark or emoji.
     */
    private function highlightLine(string $line, ?MatchResult $result): string
    {
        if ($result === null || $result->matchedIndices === []) {
            return $line;
        }

        $matchSet = array_flip($result->matchedIndices);

        // Wrap matched codepoints in ANSI bold+cyan
        $highlighted = '';
        $idx = 0;
        foreach (mb_str_split($line, 1, 'UTF-8') as $codepoint) {
            if (isset($matchSet[$idx])) {
                $highlighted .= Ansi::sgr(1, 36) . $codepoint . Ansi::reset();
            } else {
                $highlighted .= $codepoint;
            }
            $idx++;
        }

        return $highlighted;
    }

    /**
     * Classify a {@see readKey()} result as printable filter text.
     *
     * True only for well-formed UTF-8 that carries no control codepoint:
     * - any embedded C0/DEL byte (0x00-0x1F, 0x7F) disqualifies the
     *   sequence, at any position — this is what keeps ESC-bearing key
     *   events (RIS "\x1bc", Meta chords "\x1bp", CSI/SS3 bodies) out of
     *   the filter instead of echoing them raw mid-frame;
     * - a lone raw 0x80-0x9F byte is an 8-bit C1 control (or a stranded
     *   UTF-8 continuation) and fails the well-formedness probe;
     * - the encoded C1 range (U+0080-U+009F, e.g. "\xc2\x9b" = 8-bit CSI)
     *   is valid UTF-8 but still a control, so the first codepoint is
     *   checked after decoding.
     *
     * Byte-oriented patterns on purpose (no /u flag on the C0 class): C0
     * bytes can never occur inside a well-formed multi-byte sequence, and
     * a /u pattern returns false (not zero) on invalid UTF-8 — the same
     * fail-closed shape candy-core's Sanitize documents.
     */
    private function isTextKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            return false;
        }
        if (preg_match('//u', $key) !== 1) {
            return false; // invalid UTF-8: stranded continuation or raw C1
        }
        $first = mb_ord($key, 'UTF-8');
        if ($first === false) {
            return false;
        }
        // Reject encoded C1 controls (U+0080-U+009F); C0/DEL were already
        // refused byte-wise above.
        return !($first >= 0x80 && $first <= 0x9f);
    }

    /**
     * Read one complete key event.
     *
     * Key events are delivered ATOMICALLY: a single ASCII byte, a whole
     * UTF-8 codepoint (lead + continuation bytes assembled here, so the
     * filter never holds a dangling lead byte), or a full escape sequence
     * (bare ESC, CSI `ESC [ …final`, SS3 `ESC O x`, or a 2-byte Meta
     * chord). The switch in pick() then dispatches navigation keys and the
     * text intake only ever sees complete codepoints.
     */
    private function readKey(): string
    {
        $b = fread($this->in, 1);
        if ($b === false || $b === '') {
            return "\x03";
        }
        if ($b === "\x1b") {
            return $this->readEscapeSequence();
        }
        $code = ord($b);
        if ($code >= 0xc2 && $code <= 0xf4) {
            // Valid UTF-8 lead byte: assemble the rest of the codepoint.
            // (0xC0/0xC1 leads are overlong encodings and 0xF5+ are out of
            // range — both fall through as lone invalid bytes, which
            // isTextKey() refuses.)
            return $this->readCodepointTail($b, self::utf8SequenceLength($code));
        }
        // ASCII, or a lone 0x80-0xBF continuation byte / 0xF5-0xFF invalid
        // lead — isTextKey() drops the malformed shapes; only printable
        // ASCII survives.
        return $b;
    }

    /**
     * Total byte length of a UTF-8 sequence from its lead byte (0xC2-0xF4).
     */
    private static function utf8SequenceLength(int $lead): int
    {
        return match (true) {
            $lead >= 0xf0 => 4,
            $lead >= 0xe0 => 3,
            default       => 2,
        };
    }

    /**
     * Read up to $length-1 continuation bytes (0x80-0xBF) to complete a
     * multi-byte codepoint. A missing or non-continuation byte aborts
     * assembly; the resulting partial sequence is invalid UTF-8 and gets
     * dropped by isTextKey(). (A real terminal sends a codepoint's bytes
     * together, so the abort path only ever sees corrupt input — at worst
     * one following keystroke is consumed with the junk it belongs to.)
     */
    private function readCodepointTail(string $lead, int $length): string
    {
        $seq = $lead;
        stream_set_blocking($this->in, false);
        try {
            for ($i = 1; $i < $length; $i++) {
                $c = $this->nextByteWithWait();
                if ($c === '' || (ord($c) & 0xc0) !== 0x80) {
                    break;
                }
                $seq .= $c;
            }
        } finally {
            stream_set_blocking($this->in, true);
        }
        return $seq;
    }

    /**
     * ESC arrived — classify the rest of the sequence.
     *
     * `ESC [ …` is CSI (params through a 0x40-0x7E final byte), `ESC O x`
     * is SS3 (a single final byte), a lone ESC (nothing arrives within the
     * 50 ms window) is the Esc keypress itself, and anything else is a
     * 2-byte Meta/Alt chord. All but the lone ESC are opaque key events:
     * pick()'s switch ignores the unknown ones and isTextKey() refuses to
     * type them.
     */
    private function readEscapeSequence(): string
    {
        // Non-blocking window: on a real TTY this stream_select timeout is
        // what distinguishes a genuine lone ESC (no follow-on bytes) from
        // an arrow-key burst. Memory streams (tests) do not support
        // stream_select — nextByteWithWait() reads them directly.
        stream_set_blocking($this->in, false);
        try {
            $next = $this->nextByteWithWait();
            if ($next === '') {
                return "\x1b"; // lone ESC keypress, or EOF mid-sequence
            }
            if ($next === '[') {
                return "\x1b[" . $this->readCsiBody();
            }
            if ($next === 'O') {
                $final = $this->nextByteWithWait();
                return $final === '' ? "\x1bO" : "\x1bO" . $final;
            }
            return "\x1b" . $next; // Meta/Alt chord — one opaque key event
        } finally {
            stream_set_blocking($this->in, true);
        }
    }

    /**
     * CSI parameter/intermediate bytes through the 0x40-0x7E final byte,
     * bounded at 16 bytes so a stream that never sends a final byte cannot
     * wedge the pump.
     */
    private function readCsiBody(): string
    {
        $seq = '';
        for ($i = 0; $i < 16; $i++) {
            $c = $this->nextByteWithWait();
            if ($c === '') {
                break;
            }
            $seq .= $c;
            $code = ord($c);
            if ($code >= 0x40 && $code <= 0x7e) {
                break;
            }
        }
        return $seq;
    }

    /**
     * One byte, giving up (returning '') when none is pending: memory
     * streams have everything buffered already, real TTY/pipe streams get
     * the same 50 ms grace the original ESC window used.
     */
    private function nextByteWithWait(): string
    {
        if ((stream_get_meta_data($this->in)['stream_type'] ?? '') === 'MEMORY') {
            $c = fread($this->in, 1);
            return $c === false ? '' : $c;
        }
        $r = [$this->in];
        $w = null;
        $e = null;
        if (@stream_select($r, $w, $e, 0, 50000) < 1) {
            return '';
        }
        $c = fread($this->in, 1);
        return $c === false ? '' : $c;
    }

    /**
     * Toggle the controlling tty into raw mode so the picker sees
     * keys one byte at a time. Delegates to candy-core's portable
     * {@see RawMode} helper, which is a safe no-op on non-tty streams
     * (e.g. piped input in tests).
     */
    protected function setRawMode(bool $on): void
    {
        if ($on) {
            RawMode::enable($this->in);
        } else {
            RawMode::disable($this->in);
        }
    }
}

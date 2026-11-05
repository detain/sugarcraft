<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\RawMode;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Tiny terminal picker — renders a numbered list of {@see Endpoint}s,
 * lets the user move with j/k, ↑/↓, Home/End and PgUp/PgDn, narrow with
 * type-to-search,
 * and pick with Enter. Returns the selected Endpoint or null on Esc or
 * the cancel byte; on a real terminal Ctrl-C raises SIGINT, which the
 * picker traps to restore the terminal before exiting 130.
 *
 * Why not the full SugarWidgets `List` widget? That's overkill for a
 * one-shot picker that we immediately replace with `pcntl_exec`.
 * This class only needs to draw a static list, read a few keys,
 * and exit fast — no event loop, no full Program lifecycle.
 *
 * Test seam: the streams are constructor arguments and tests subclass
 * this class overriding {@see setRawMode()} (and {@see detectColumns()}
 * for the width-clamp pin) — there is no
 * `inputStream()` / `outputStream()` to override.
 */
class Picker
{
    /**
     * Palette bytes for the selected-row marker and the filter echo, as
     * exactly produced by Ansi::sgr(1,36)/Ansi::reset()/Ansi::sgr(36).
     * Byte-pinned by the picker tests (2026-10-23, T4 — extracted from the
     * inline literals without changing a single emitted byte).
     */
    private const SELECTED_MARKER = "\x1b[1;36m▸\x1b[0m ";
    private const UNSELECTED_MARKER = "  ";
    private const FILTER_COLOR = "\x1b[36m";
    /**
     * Rows the visible window shows at once (F-25). A frame with more rows
     * than the terminal has soft-wraps and strands chrome; ten keeps the
     * picker inside any real window and gives PgUp/PgDn a page to scroll.
     */
    private const WINDOW_ROWS = 10;

    /** @var resource */
    protected $in;
    /** @var resource */
    protected $out;
    private string $filter = '';
    private int $cursor = 0;
    private SmithWatermanMatcher $matcher;
    /**
     * Denominator for the "N of M" count row while filtering: total
     * endpoints handed to pick(), so `draw()` still takes its test-pinned
     * single argument (2026-10-23, U1).
     */
    private int $totalEndpoints = 0;
    private bool $aborted = false;
    /**
     * Saved pcntl_async_signals() state so the SIGINT arm in
     * armCancelSignal() is strictly picker-scoped.
     */
    private ?bool $asyncSignalsBeforeArm = null;
    /** Terminal width in columns; null = probed-and-not-a-tty, unset = not yet probed. */
    private ?int $cols = null;
    private bool $colsProbed = false;

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
        $this->totalEndpoints = count($endpoints);
        $this->aborted = false;
        $this->setRawMode(true);
        $this->armCancelSignal();
        try {
            while (true) {
                $matches = $this->filterMatches($endpoints);
                if ($this->cursor >= count($matches)) {
                    $this->cursor = max(0, count($matches) - 1);
                }
                if ($this->cursor < 0) {
                    $this->cursor = 0;
                }
                $this->draw($matches);

                $key = $this->readKey();
                switch ($key) {
                    case "\x03": /* cancel byte: stdin EOF, or ^C where SIGINT is blocked */
                    case "\x1b": /* ESC */
                        $this->aborted = true;
                        return null;
                    case "\r":
                    case "\n":
                        if ($matches === []) {
                            continue 2;
                        }
                        return $matches[$this->cursor]['endpoint'];
                    case "\x1b[B":
                        if ($this->cursor < count($matches) - 1) {
                            $this->cursor++;
                        }
                        break;
                    case "\x1b[A":
                        if ($this->cursor > 0) {
                            $this->cursor--;
                        }
                        break;
                    case 'j':
                    case 'k':
                        // j/k navigate only while the filter is empty. Once
                        // the user has started typing, letters belong to the
                        // query — an unqualified j/k used to eat them, making
                        // names like 'jumpbox' untypeable while arrows still
                        // navigated unconditionally.
                        if ($this->filter === '') {
                            if ($key === 'j' && $this->cursor < count($matches) - 1) {
                                $this->cursor++;
                            } elseif ($key === 'k' && $this->cursor > 0) {
                                $this->cursor--;
                            }
                            break;
                        }
                        if ($this->isTextKey($key)) {
                            $this->filter .= $key;
                            $this->cursor = 0;
                        }
                        break;
                    case "\x1b[H":
                    case "\x1b[1~":
                    case "\x1b[7~":
                    case "\x1bOH": /* SS3 Home */
                        // F-25: readEscapeSequence decoded these keys all
                        // along; the switch silently dropped them. Home/End
                        // jump to the ends of the match list, PgUp/PgDn
                        // scroll a visible-window page.
                        $this->cursor = 0;
                        break;
                    case "\x1b[F":
                    case "\x1b[4~":
                    case "\x1b[8~":
                    case "\x1bOF": /* SS3 End */
                        $this->cursor = max(0, count($matches) - 1);
                        break;
                    case "\x1b[5~":
                    case "\x1bOP": /* SS3 PgUp */
                        $this->cursor = max(0, $this->cursor - $this->pageSpan($matches));
                        break;
                    case "\x1b[6~":
                    case "\x1bOQ": /* SS3 PgDn */
                        $this->cursor = $matches === []
                            ? 0
                            : min(count($matches) - 1, $this->cursor + $this->pageSpan($matches));
                        break;
                    case "\x7f": /* backspace */
                    case "\x08":
                        $before = $this->filter;
                        // Codepoint-granular trim. readKey() delivers whole
                        // UTF-8 codepoints, and the matcher/highlighter index
                        // the filter by codepoint too (sugar-fuzzy CharFold,
                        // see highlightLine()), so the delete unit must be the
                        // codepoint. A byte-wise substr here stranded dangling
                        // continuation bytes mid-character and echoed invalid
                        // UTF-8 to the terminal (mirrors sugar-quickpick
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
            $this->disarmCancelSignal();
            if ($this->aborted) {
                // Esc/^C: erase the transient frame before releasing the
                // cursor line. After a successful pick the frame is a
                // meaningful record (and ssh takes the screen over
                // anyway), so only the cancel path cleans up — and only
                // the cancel path may show eraseScreen, pinned by the
                // sanitize suite (2026-10-23, T1).
                fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseScreen());
            }
            fwrite($this->out, "\n");
        }
    }

    /**
     * Arm the ^C path on a real terminal. sugar-core RawMode leaves ISIG on
     * (stty -icanon -echo keeps the signal machinery alive), so Ctrl-C
     * arrives as SIGINT and never as a byte: without this arm the pick()
     * finally block never runs, the terminal is left non-canonical, and the
     * 130 exit is only the shell's coincidence. The handler restores the
     * terminal FIRST, mirrors the cancel frame, and exits 130 with the same
     * cli.cancelled line the byte-cancel path produces in bin. ext-pcntl is
     * a hard composer requirement; the guard below only covers stripped
     * builds, where ^C keeps its historical unrecovered behavior — a
     * disclosed fallback, never a silently assumed one (F-3).
     */
    private function armCancelSignal(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        $this->asyncSignalsBeforeArm = pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (int $signal): void {
            pcntl_signal($signal, SIG_DFL);
            pcntl_async_signals($this->asyncSignalsBeforeArm ?? false);
            $this->setRawMode(false);
            fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseScreen());
            fwrite($this->out, "\n");
            fwrite(STDERR, Lang::t('cli.cancelled') . "\n");
            exit(130);
        });
    }

    /**
     * Undo armCancelSignal(): a pick() that ended normally (or will be
     * re-entered) must not leak a picker-scoped handler or async dispatch
     * into the rest of the process.
     */
    private function disarmCancelSignal(): void
    {
        if ($this->asyncSignalsBeforeArm !== null && function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals($this->asyncSignalsBeforeArm);
            $this->asyncSignalsBeforeArm = null;
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

        // Filter to only matched (score > 0) and sort by score descending.
        // usort() has been stable since PHP 8.0 — equal scores keep config
        // order (pinned by testEqualScoresPreserveConfigOrder, 2026-10-23).
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
        fwrite($this->out, Lang::t('picker.title') . "\r\n");
        // Belt-and-braces: intake (isTextKey) already refuses control bytes,
        // but the echo passes through the same sanitizer the config-derived
        // strings use, so no raw ESC can reach the terminal from here either.
        // The 'en' filter prompt is byte-pinned as 'filter: ' by the
        // integrity suite — picker.filter must keep that shape verbatim.
        $filterLine = Lang::t('picker.filter') . self::FILTER_COLOR . $this->stripControls($this->filter) . Ansi::reset();
        fwrite($this->out, $this->clampRow($filterLine) . "\r\n");
        if ($this->filter !== '' && $this->totalEndpoints > 0) {
            // Honest funnel: how much of the config the filter killed
            // (2026-10-23, U1). The count row stays out of the filter line
            // itself (the integrity suite counts ESC bytes per 'filter: '
            // line) and out of reflection draws where totalEndpoints is
            // still 0.
            fwrite($this->out, Lang::t('picker.count', [
                'shown' => (string) count($matches),
                'total' => (string) $this->totalEndpoints,
            ]) . "\r\n");
        }
        if ($matches === []) {
            fwrite($this->out, Lang::t('picker.no_matches') . "\r\n");
        }
        // F-25: render-side visible window. windowTop is recomputed from
        // the cursor every frame, so no navigation branch has to maintain
        // it; array_slice keeps original keys, so $i still identifies the
        // absolute row for the marker test.
        $total   = count($matches);
        $visible = min(self::WINDOW_ROWS, $total);
        $top     = max(0, min($this->cursor, $total - $visible));
        foreach (array_slice($matches, $top, $visible, true) as $i => $record) {
            $e = $record['endpoint'];
            $marker = $i === $this->cursor ? self::SELECTED_MARKER : self::UNSELECTED_MARKER;
            // The displayLine is already sanitized from filterMatches()
            $line = $this->highlightLine($record['displayLine'], $record['result']);
            if ($e->description !== null && $e->description !== '') {
                $line .= '  ' . Ansi::sgr(Ansi::FAINT) . $this->stripControls($e->description) . Ansi::reset();
            }
            fwrite($this->out, $this->clampRow("{$marker}{$line}") . "\r\n");
        }
        fwrite($this->out, "\r\n" . Lang::t('picker.help') . "\r\n");
    }

    /**
     * Page span for PgUp/PgDn (F-25): what the visible window shows at
     * once, never below one.
     *
     * @param array<int|string, array<string, mixed>> $matches
     */
    private function pageSpan(array $matches): int
    {
        return min(self::WINDOW_ROWS, max(1, count($matches)));
    }

    /**
     * Truncate a composed row to one less than the terminal width, so a
     * long host or description cannot force a soft wrap that strands the
     * overflow under the next redraw (eraseToEnd only clears to the end of
     * the CURRENT line; 2026-10-23, T2). Non-terminals — every byte-pinned
     * picker test and any piped run — probe null and pass through unclamped.
     */
    private function clampRow(string $row): string
    {
        $cols = $this->columns();
        if ($cols === null) {
            return $row;
        }
        $limit = max(8, $cols - 1);
        if (Width::of($row) <= $limit) {
            return $row;
        }
        // One cell is reserved for the ellipsis itself.
        return Width::truncateAnsi($row, $limit - 1) . '…';
    }

    private function columns(): ?int
    {
        if (!$this->colsProbed) {
            $this->colsProbed = true;
            $this->cols = $this->detectColumns();
        }
        return $this->cols;
    }

    /**
     * Test seam: the real terminal width when $out is a tty, null otherwise.
     */
    protected function detectColumns(): ?int
    {
        $tty = new Tty($this->out);
        if (!$tty->isTty()) {
            return null;
        }
        return $tty->size()['cols'] ?? null;
    }

    /**
     * Canonical untrusted-bytes policy for everything the picker writes to
     * the terminal. {@see Sanitize::untrustedForDisplay()} strips escape
     * sequences, C0 controls and the encoded-C1/C1-shim bytes the old local
     * byte-sweep missed (\xC2[\x80-\x9F] reaches a terminal as a real CSI
     * when the C1 range is interpreted), and marks invisible formatting
     * (bidi overrides, zero-width joins) as visible <U+XXXX> sentinels
     * instead of letting them silently rewrite what a row says.
     *
     * CR, LF and TAB survive sanitizing (the boundary keeps line endings for
     * paste fidelity), so they are folded to spaces here: a config field's
     * newline must not forge an extra visual row through the display path.
     */
    private function stripControls(string $s): string
    {
        $s = Sanitize::untrustedForDisplay($s);

        return str_replace(["\t", "\r", "\n"], ' ', $s);
    }

    /**
     * Apply bold+cyan highlighting to matched character positions in a display line.
     *
     * The $line argument is the sanitized display string; $result contains
     * matchedIndices computed against that same string in filterMatches().
     * We walk CODEPOINTS — the same index domain sugar-fuzzy scores in
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
     * fail-closed shape sugar-core's Sanitize documents.
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
     * keys one byte at a time. Delegates to sugar-core's portable
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

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Display-width measurement for terminal text.
 *
 * Width is counted in monospace cells. ANSI escape sequences are stripped
 * before measurement. Wide East-Asian characters and most emoji count as 2;
 * zero-width and combining marks count as 0; a `\t` counts {@see TAB_WIDTH}.
 *
 * Everything here measures and cuts at GRAPHEME CLUSTER boundaries, and it is
 * one splitter ({@see nextCluster()}) for both — `string()` and the truncators
 * used to run different ones and disagree by cells on emoji sequences (E68).
 * A consequence worth knowing before writing an assertion against this class:
 * a cluster's width is not a property of its codepoints alone but of what
 * PRECEDES it, because a combining mark or emoji modifier joins the character
 * before it. `Width::string(" " . U+1F3FD)` is 1 and
 * `Width::string("\t" . U+1F3FD)` is TAB_WIDTH + 2, from the same modifier.
 * Any transform that rewrites content — tab expansion, padding, joining —
 * can therefore move a width without adding or removing a single glyph.
 *
 * WIDTH POLICY (SC-1 census against Unicode 16.0.0 UCD, 2026-11):
 * - ZERO WINS OVER WIDE. A codepoint that is Mn or Me (non-spacing / enclosing
 *   mark) scores 0 even when its slot sits inside a legacy Wide block leg —
 *   U+302A..U+302D and U+3099..U+309A live inside the CJK block legs and are
 *   combining marks, not ideographs. Mc (spacing combining) is NOT zero: its
 *   spacing is part of its shape, so it scores 1 — or 2 when East Asian Wide.
 * - East_Asian_Width W and F score 2; A, N, H, Na and unassigned score 1.
 *   Ambiguous-in-CJK (the GNU wcwidth table rendering A as 2) is NOT applied:
 *   PHP's mb_strwidth, the terminals in this fleet, and every golden in the
 *   repo agree on A = 1. One deliberate exception rides the block shape
 *   against the data: the ~257 ASSIGNED EAW=Neutral text-presentation emoji
 *   inside the legacy U+1F300..U+1F64F, U+1F680..U+1F6FF and U+1F900..U+1F9FF
 *   block legs (e.g. U+1F321) score 2 with their block, matching fleet
 *   wcwidth parity — the terminals that paint this family lay those slots
 *   two cells wide too.
 * - Holes inside the pre-2026 block legs (U+3248..U+324F, Plane-2/3 gaps) keep
 *   the block's charge — those legs are Kuhn-2006 spans and the EAW file
 *   itself defaults Plane-2/3 undesignated regions to W. Every leg ADDED by
 *   this census is an exact UCD run: BMP/Plane-1 unassigned slots (U+FE53,
 *   U+FE67, U+1AFF4, U+1AFFC) are NOT padded wide — they stay 1, which is
 *   what an unassigned slot measures as everywhere else in the table.
 * - The combining table below is Unicode-16.0 Mn+Me data; the fixed ladder
 *   above it stays for the hot cases and for forward-compat padding tails
 *   (U+1AB0..U+1AFF, U+20D0..U+20FF are zero beyond their assigned marks).
 */
final class Width
{
    /**
     * Bounded memo for {@see string()}, keyed by the exact input string.
     *
     * `string()` is the hottest util in the repo (called per grapheme, per
     * cell, per frame). The computation is a pure function of `$s` — ANSI
     * strip + grapheme split + per-cluster width — so its result can be
     * memoized safely. Bounded to {@see MEMO_MAX} entries so a long-running
     * session with an unbounded distinct-string working set can't leak.
     *
     * @var array<string, int>
     */
    private static array $memo = [];

    /**
     * Hard cap on {@see $memo} entries. When reached, the oldest half is
     * dropped in one pass (amortized O(1) eviction) rather than one-shift-
     * per-insert (O(n)); this keeps the memo a net win even when the
     * working set of distinct strings exceeds the cap.
     */
    private const MEMO_MAX = 2048;

    /**
     * Cells a `\t` is charged, and the default every tab-expanding renderer
     * in the stack must share.
     *
     * E69: `string()` scored a tab 0 while `\SugarCraft\Style\Style::render()`
     * replaced it with `str_repeat(' ', $tabWidth)` — default 4 — *before* its
     * own measurement. A caller that budgeted with `Width` and laid out with
     * `Style` was using two measures 4 cells apart per tab, and every assertion
     * written in terms of `Width` was blind to the gap. `Style`'s default now
     * reads this constant, so the two move together instead of agreeing by
     * coincidence.
     *
     * **Domain.** This is a fixed per-tab charge, not tab-STOP arithmetic: a
     * real terminal advances a tab to the next stop, which depends on the
     * column the tab sits in, and no context-free measure can model that. It is
     * exact for content rendered through a `Style` at the default tab width.
     * Measured across the non-vendor tree: no PRODUCTION file calls
     * `Style::tabWidth()` at all, and the only non-default widths anywhere are
     * in `sugar-style/tests/StyleTest.php`, which exercises the knob itself
     * (2, 0, and 8-then-unset) rather than any `Width` agreement. A `Style`
     * given `tabWidth(0)` (literal tabs) or any other non-default width still
     * disagrees with `string()`; that residue is deliberate and recorded rather
     * than papered over.
     *
     * Not to be confused with `\SugarCraft\Dashboard\Components\Card\Highlight`'s
     * own `$tabWidth`, an unrelated field on a different class that happens to
     * share the name and the default of 4 and is settable to any value >= 1.
     */
    public const TAB_WIDTH = 4;

    /**
     * Cell width of a string after stripping ANSI sequences.
     */
    public static function string(string $s): int
    {
        // Values are always non-negative ints (never null), so isset() is a
        // correct and faster presence check than array_key_exists().
        if (isset(self::$memo[$s])) {
            return self::$memo[$s];
        }
        $width = self::compute($s);
        if (\count(self::$memo) >= self::MEMO_MAX) {
            self::$memo = \array_slice(self::$memo, self::MEMO_MAX >> 1, null, true);
        }
        return self::$memo[$s] = $width;
    }

    /**
     * Uncached width computation backing {@see string()}.
     *
     * A plain sum of {@see graphemeWidth()} over {@see graphemes()}, with NO
     * cross-cluster state. That is the whole of it, and the reason it can be
     * is E68: both sides now walk ICU's UAX#29 segmenter, so a ZWJ that
     * actually joins two emoji is already INSIDE one cluster and is scored
     * once, by that cluster's base.
     *
     * WHAT THIS USED TO SAY, in code rather than prose: a ZWJ look-ahead plus
     * an `$inZwjSequence` flag charged the codepoint before a ZWJ 2 cells and
     * then suppressed every emoji after it. WHAT IS TRUE NOW: that machine
     * was written when `string()` split per CODEPOINT (pre-E68), where a ZWJ
     * really did arrive as a sibling of the emoji it joined. Under cluster
     * segmentation a bare ZWJ cluster means the opposite — UAX#29 broke
     * BEFORE it, so nothing joined, and each neighbour renders on its own.
     * WHY THE REMOVAL EARNS ITS PLACE: **under ICU segmentation** — the only
     * segmentation this package supports, see the domain note below — the
     * machine's two clauses had become pure loss.
     * `Width::string("\t" . ZWJ . U+1F44D)` scored 0 for a run
     * `Style::render()` lays out as 6 cells (E73, measured on PHP 8.3.6 with ext-intl
     * ICU 74.2 / Unicode 15.1): the look-ahead zeroed the tab and the
     * flag zeroed the emoji.
     * Under-counting is the frame-corrupting direction here — the diff
     * renderer paints one line per terminal row — so this is not a tidy-up.
     *
     * **Domain of "pure loss": the ICU path only.** With ext-intl absent
     * {@see nextCluster()} degrades to a per-CODEPOINT splitter, and there
     * the removed machine was load-bearing — it is what kept a real ZWJ
     * sequence from being scored once per joined emoji. That branch is a
     * documented seam, not a supported path; `sugar-core/composer.json`
     * hard-requires `ext-intl`. The measurement and the reasoning are at
     * {@see nextCluster()}.
     */
    private static function compute(string $s): int
    {
        $clean = Ansi::strip($s);
        if ($clean === '') {
            return 0;
        }
        /** @var string|null $asciiRuns */
        static $asciiRuns = null;
        $asciiRuns ??= implode('', array_map('chr', range(0x20, 0x7E)));
        $width = 0;
        $len = strlen($clean);
        $i = 0;
        while ($i < $len) {
            // M19 perf fast path: printable ASCII (0x20-0x7E) is always a
            // one-cell, zero-combining character on its own — UTF-8
            // continuation bytes and every combining mark are >= 0x80, so a
            // maximal run of these bytes cannot share a cluster with its
            // neighbours. Consume the whole run without touching the
            // segmenter; everything else falls through to the cluster walk.
            $run = strspn($clean, $asciiRuns, $i);
            if ($run > 0) {
                $width += $run;
                $i += $run;
                continue;
            }
            $cluster = self::nextCluster($clean, $i);
            if ($cluster === '') {
                // nextCluster() is contracted to return at least one byte;
                // this guards the loop against a future regression rather
                // than a reachable state (same shape as graphemes()).
                $cluster = $clean[$i];
            }
            $width += self::graphemeWidth($cluster);
            $i += strlen($cluster);
        }
        return $width;
    }

    /**
     * Alias of {@see string()} for ergonomic API.
     *
     * Measures the display width of a string where:
     * - ASCII characters = 1 cell
     * - CJK characters = 2 cells
     * - Zero-width and combining characters = 0 cells
     * - Emoji and ZWJ sequences are measured per-cluster
     */
    public static function of(string $s): int
    {
        return self::string($s);
    }

    /**
     * Truncate $s so its visible width does not exceed $max.
     * ANSI sequences inside $s are dropped.
     */
    public static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        $clean = Ansi::strip($s);
        $out = '';
        $w = 0;
        foreach (self::graphemes($clean) as $g) {
            $gw = self::graphemeWidth($g);
            if ($w + $gw > $max) {
                break;
            }
            $out .= $g;
            $w += $gw;
        }
        return $out;
    }

    /**
     * Clip $s to at most $maxBytes BYTES, the marker INCLUDED in that budget
     * ("marker-in-budget": the result never exceeds $maxBytes, so a caller
     * sizing a buffer, a prompt slot or a column by bytes can trust it).
     *
     * Text that already fits is returned untouched (no marker). Otherwise the
     * text is cut at a UTF-8 codepoint boundary — never mid-sequence — and the
     * marker appended. A marker that alone exceeds the budget is itself cut to
     * fit, so the bound holds for every input. ANSI is NOT interpreted: this
     * is a byte primitive; a caller clipping styled text clips the plain
     * string first. Boundaries are codepoint-, not grapheme-aware, so a clip
     * can still separate a base character from its combining mark — use
     * {@see clipCells()} for text that is displayed.
     *
     * $maxBytes <= 0 yields ''.
     */
    public static function clipBytes(string $text, int $maxBytes, string $marker = '…'): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (\strlen($text) <= $maxBytes) {
            return $text;
        }

        $markerBytes = \strlen($marker);
        if ($markerBytes >= $maxBytes) {
            return self::utf8Prefix($marker, $maxBytes);
        }

        return self::utf8Prefix($text, $maxBytes - $markerBytes) . $marker;
    }

    /**
     * {@see clipBytes()}'s display-cell twin: clip $s to at most $maxCells
     * terminal cells with the marker INCLUDED in the budget. Text that fits is
     * returned untouched (ANSI preserved); a clip goes through
     * {@see truncate()}, so it is grapheme-safe and ANSI is dropped, as there.
     * A marker wider than the budget is itself truncated to fit.
     */
    public static function clipCells(string $s, int $maxCells, string $marker = '…'): string
    {
        if ($maxCells <= 0) {
            return '';
        }
        if (self::string($s) <= $maxCells) {
            return $s;
        }

        $markerCells = self::string($marker);
        if ($markerCells >= $maxCells) {
            return self::truncate($marker, $maxCells);
        }

        return self::truncate($s, $maxCells - $markerCells) . $marker;
    }

    /**
     * The longest prefix of $s no longer than $bytes that does not end inside a
     * UTF-8 multi-byte sequence (continuation bytes 0x80..0xBF are walked back
     * over to the lead byte, which is dropped with them).
     */
    private static function utf8Prefix(string $s, int $bytes): string
    {
        if ($bytes >= \strlen($s)) {
            return $s;
        }
        while ($bytes > 0 && (\ord($s[$bytes]) & 0xC0) === 0x80) {
            $bytes--;
        }

        return substr($s, 0, $bytes);
    }

    /**
     * Truncate $s to display width $max by removing the MIDDLE and
     * inserting $ellipsis, keeping both ends visible. ANSI sequences are
     * dropped. Returns $s (ANSI-stripped) unchanged when it already fits.
     *
     * Useful for long identifiers/paths where both ends carry meaning —
     * e.g. `wait/synch/mutex/…/THR_LOCK_myisam` or `/var/lib/…/data.db` —
     * which the plain end-truncating {@see truncate()} would mangle.
     */
    public static function truncateMiddle(string $s, int $max, string $ellipsis = '…'): string
    {
        if ($max <= 0) {
            return '';
        }
        $clean = Ansi::strip($s);
        if (self::string($clean) <= $max) {
            return $clean;
        }
        $ellipsisWidth = self::string($ellipsis);
        if ($ellipsisWidth >= $max) {
            // No room for any context around the ellipsis — fall back to a
            // plain head-truncation so the result still fits $max cells.
            return self::truncate($clean, $max);
        }

        $budget = $max - $ellipsisWidth;
        $headBudget = intdiv($budget, 2);
        $tailBudget = $budget - $headBudget;

        $clusters = self::graphemes($clean);
        $n = count($clusters);

        // Head: consume clusters from the front up to $headBudget cells.
        $head = '';
        $hw = 0;
        $i = 0;
        for (; $i < $n; $i++) {
            $gw = self::graphemeWidth($clusters[$i]);
            if ($hw + $gw > $headBudget) {
                break;
            }
            $head .= $clusters[$i];
            $hw += $gw;
        }

        // Tail: consume clusters from the back up to $tailBudget cells, never
        // crossing into the clusters already taken by the head.
        $tail = '';
        $tw = 0;
        for ($j = $n - 1; $j >= $i; $j--) {
            $gw = self::graphemeWidth($clusters[$j]);
            if ($tw + $gw > $tailBudget) {
                break;
            }
            $tail = $clusters[$j] . $tail;
            $tw += $gw;
        }

        return $head . $ellipsis . $tail;
    }

    /**
     * Pad `$s` on the right with spaces so its visible width reaches
     * `$width`. ANSI sequences in `$s` are skipped when measuring. If
     * `$s` already meets or exceeds `$width`, it's returned as-is.
     */
    public static function padRight(string $s, int $width, string $pad = ' '): string
    {
        $w = self::string($s);
        if ($w >= $width || $pad === '') {
            return $s;
        }
        return $s . str_repeat($pad, $width - $w);
    }

    /**
     * Pad `$s` on the left with spaces so its visible width reaches
     * `$width`. Useful for right-aligned cells. ANSI sequences in
     * `$s` are skipped when measuring.
     */
    public static function padLeft(string $s, int $width, string $pad = ' '): string
    {
        $w = self::string($s);
        if ($w >= $width || $pad === '') {
            return $s;
        }
        return str_repeat($pad, $width - $w) . $s;
    }

    /**
     * Pad `$s` on both sides so its visible width reaches `$width`,
     * centering the text. Excess goes on the right when the gap is odd.
     */
    public static function padCenter(string $s, int $width, string $pad = ' '): string
    {
        $w = self::string($s);
        if ($w >= $width || $pad === '') {
            return $s;
        }
        $gap = $width - $w;
        $left = intdiv($gap, 2);
        $right = $gap - $left;
        return str_repeat($pad, $left) . $s . str_repeat($pad, $right);
    }

    /**
     * Soft-wrap `$s` to `$max` cell-columns, breaking on word boundaries
     * where possible and falling back to mid-grapheme cuts when a single
     * word exceeds the width. Returns the wrapped text with `\n`
     * separators. ANSI escape sequences are stripped before wrapping
     * (use {@see wrapAnsi()} to preserve inline styling).
     *
     * Behaviour mirrors lipgloss's wordwrap algorithm: trailing spaces
     * on a line collapse, but explicit `\n` characters in the input are
     * honored as hard breaks.
     *
     * Malformed UTF-8 is kept byte-for-byte (each stray byte a 0-width
     * cluster, as in {@see truncate()}); a paragraph containing any breaks
     * only on ASCII whitespace, since Unicode spaces cannot be recognised in
     * bytes that are not UTF-8.
     */
    public static function wrap(string $s, int $max): string
    {
        if ($max <= 0 || $s === '') {
            return $s;
        }
        $clean = Ansi::strip($s);
        $out = [];
        foreach (explode("\n", $clean) as $paragraph) {
            $out[] = self::wrapParagraph($paragraph, $max);
        }
        return implode("\n", $out);
    }

    /**
     * ANSI-aware companion to {@see wrap()}. Preserves inline CSI / OSC
     * sequences across line breaks: a colour set on line N stays active
     * on line N+1 (no SGR reset is auto-emitted; callers wanting that
     * should append `Ansi::reset()` themselves).
     *
     * Hard breaks are honoured the way {@see wrap()} honours them: `\n`
     * and `\r\n` both end the line, and the output separator is always a
     * bare `\n`. No returned line is wider than `$max` unless it is a
     * single grapheme that is itself wider than the whole budget.
     */
    public static function wrapAnsi(string $s, int $max): string
    {
        if ($max <= 0 || $s === '') {
            return $s;
        }
        $len = strlen($s);
        $i = 0;
        $line = '';
        $lineWidth = 0;
        $word = '';
        $wordWidth = 0;
        $lines = [];

        $flushLine = static function () use (&$line, &$lineWidth, &$lines): void {
            $lines[] = rtrim($line);
            $line = '';
            $lineWidth = 0;
        };
        // Every place a finished word joins the line must ask whether it
        // still fits. The `\n` branch and the over-wide-cluster branch used
        // to append unconditionally, so the last word before a hard break
        // rode onto a line that had no room for it — `"aaaa bbbb x\ny"` at
        // 10 came back with an 11-cell first line, breaking the renderer's
        // one-row-per-line invariant.
        // WHY $word/$wordWidth are PARAMETERS and not by-ref captures: the
        // analyser knows a captured variable only by the value it holds at
        // capture time — $word is '' there — so the guard below read as
        // provably dead code and its body as unreachable. Passing the
        // pending word in keeps every guard provable from the call site;
        // the accumulator reset moves to the callers that keep using it.
        $commitWord = static function (string $word, int $wordWidth) use (&$line, &$lineWidth, $max, $flushLine): void {
            if ($word === '') {
                return;
            }
            if ($lineWidth + $wordWidth > $max && $line !== '') {
                $flushLine();
            }
            $line .= $word;
            $lineWidth += $wordWidth;
        };

        while ($i < $len) {
            $b = $s[$i];

            // Pass-through: CSI / OSC pass into the current word so the colour
            // attaches to the word that ends up on a new line.
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $word .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($s[$j] === "\x07") {
                        $j++;
                        break;
                    }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') {
                        $j += 2;
                        break;
                    }
                    $j++;
                }
                $word .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }

            // `\r\n` is one hard break, as in wrap(). Left to nextCluster()
            // it came back as a single 0-width grapheme glued into the
            // running word: the CR leaked into the output and the column
            // count never reset, so the next row was measured as a
            // continuation of the previous one.
            if ($b === "\n" || ($b === "\r" && ($s[$i + 1] ?? '') === "\n")) {
                $commitWord($word, $wordWidth);
                $word = '';
                $wordWidth = 0;
                $flushLine();
                $i += $b === "\r" ? 2 : 1;
                continue;
            }

            if ($b === ' ' || $b === "\t") {
                $commitWord($word, $wordWidth);
                $word = '';
                $wordWidth = 0;
                // E69: this charged a tab 1 cell — a THIRD tab measure, after
                // string()'s 0 and Style::render()'s TAB_WIDTH. Route it
                // through graphemeWidth() so there is only one.
                $bw = self::graphemeWidth($b);
                if ($lineWidth + $bw <= $max) {
                    $line .= $b;
                    $lineWidth += $bw;
                } else {
                    $flushLine();
                }
                $i++;
                continue;
            }

            $cluster = self::nextCluster($s, $i);
            $cw = self::graphemeWidth($cluster);
            // If even the running word would overflow, hard-break it.
            if ($cw > $max) {
                $commitWord($word, $wordWidth);
                $word = '';
                $wordWidth = 0;
                if ($line !== '') {
                    $flushLine();
                }
                $lines[] = $cluster;
                $i += strlen($cluster);
                continue;
            }
            if ($wordWidth + $cw > $max) {
                if ($line !== '') {
                    $flushLine();
                }
                $lines[] = rtrim($word);
                $word = $cluster;
                $wordWidth = $cw;
            } else {
                $word .= $cluster;
                $wordWidth += $cw;
            }
            $i += strlen($cluster);
        }
        $commitWord($word, $wordWidth);
        if ($line !== '') {
            $lines[] = rtrim($line);
        }
        return implode("\n", $lines);
    }

    private static function wrapParagraph(string $s, int $max): string
    {
        $words = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($words === false) {
            // `/u` refuses malformed UTF-8 outright. The old `?: []` read that
            // failure as "no words" and returned '' — silently dropping the
            // whole paragraph, while truncate()/string() on the same bytes
            // pass them through as 0-width clusters (graphemes() walks bytes
            // when ICU cannot). Split on the ASCII whitespace set at byte
            // level instead — NOT `\s` without `/u`, whose locale tables are
            // free to claim 0x85/0xA0, both of which are UTF-8 continuation
            // bytes (`à` is C3 A0) and would cut valid characters in half.
            $words = preg_split('/([ \t\n\r\x0B\x0C]+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($words === false) {
                throw new \RuntimeException(
                    'Width::wrap() could not split paragraph: ' . preg_last_error_msg(),
                );
            }
        }
        $line = '';
        $lineWidth = 0;
        $lines = [];
        foreach ($words as $token) {
            if ($token === '') {
                continue;
            }
            $w = self::string($token);
            if (preg_match('/^\s+$/u', $token) === 1) {
                if ($line === '') {
                    continue;
                }
                if ($lineWidth + $w > $max) {
                    $lines[] = $line;
                    $line = '';
                    $lineWidth = 0;
                    continue;
                }
                $line .= $token;
                $lineWidth += $w;
                continue;
            }
            // Hard-break a single oversize word.
            if ($w > $max) {
                if ($line !== '') {
                    $lines[] = rtrim($line);
                    $line = '';
                    $lineWidth = 0;
                }
                $remaining = $token;
                while (self::string($remaining) > $max) {
                    $chunk = self::truncate($remaining, $max);
                    if ($chunk === '') {
                        // 15b-26: the leading cluster is wider than the whole
                        // budget (a CJK glyph or wide emoji at $max = 1), so
                        // truncate() fits nothing and the loop would never
                        // advance. Emit that cluster alone on an over-wide
                        // row — wrapAnsi() does the same — because every
                        // pass must consume at least one cluster, and an
                        // over-wide row beats a hang or a dropped glyph.
                        $chunk = self::nextCluster($remaining, 0);
                    }
                    $lines[] = $chunk;
                    $remaining = substr($remaining, strlen($chunk));
                }
                if ($remaining !== '') {
                    $line = $remaining;
                    $lineWidth = self::string($remaining);
                }
                continue;
            }
            if ($lineWidth + $w > $max) {
                $lines[] = rtrim($line);
                $line = $token;
                $lineWidth = $w;
                continue;
            }
            $line .= $token;
            $lineWidth += $w;
        }
        if ($line !== '') {
            $lines[] = rtrim($line);
        }
        return implode("\n", $lines);
    }

    /**
     * Truncate $s to {@see $max} cells while preserving inline ANSI escape
     * sequences. CSI / OSC sequences pass through with zero width and are
     * never split; visible graphemes accumulate width and the loop stops
     * once the budget is consumed. Trailing ANSI sequences after the cut
     * point are still appended so dangling SGR resets aren't lost.
     *
     * This scanner recognises only CSI and OSC, unlike {@see Ansi::strip()}
     * (which since the ANSI audit also treats DCS/SOS/PM/APC payloads and
     * the 8-bit C1 forms as zero-width). A row carrying those richer
     * sequences therefore measures narrower under {@see self::of()} than it
     * truncates here — the divergence `PaneWidthInvariantTest` pins, and
     * why the fitter keeps its byte-identical fast-path branch.
     */
    public static function truncateAnsi(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        $len = strlen($s);
        $out = '';
        $w   = 0;
        $i   = 0;
        $budgetReached = false;

        while ($i < $len) {
            $b = $s[$i];

            // Pass-through: CSI sequences (ESC [ ... final).
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $out .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            // Pass-through: OSC sequences (ESC ] ... ST/BEL).
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($s[$j] === "\x07") {
                        $j++;
                        break;
                    }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') {
                        $j += 2;
                        break;
                    }
                    $j++;
                }
                $out .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }

            // No more visible budget — keep scanning so trailing ANSI
            // sequences (e.g. SGR resets) get harvested by the loop above,
            // but skip visible characters silently.
            if ($budgetReached) {
                $cluster = self::nextCluster($s, $i);
                $i += strlen($cluster);
                continue;
            }

            $cluster = self::nextCluster($s, $i);
            $gw      = self::graphemeWidth($cluster);
            if ($w + $gw > $max) {
                $budgetReached = true;
                continue;
            }
            $out .= $cluster;
            $w   += $gw;
            $i   += strlen($cluster);
        }
        return $out;
    }

    /**
     * Drop the first `$skip` visible cells from `$s` and return the
     * remainder (with all ANSI escape sequences from the dropped
     * region preserved at the start so the leftover text picks up
     * the correct SGR state).
     *
     * Cell-aware complement to {@see truncateAnsi()}: where
     * `truncateAnsi` returns "first N cells", `dropAnsi` returns
     * "everything after the first N cells". Together they let
     * callers slice an ANSI-coloured row at arbitrary cell columns —
     * the primitive {@see \SugarCraft\Style\Canvas} uses to
     * paste overlay layers over a base view at exact positions.
     *
     * If `$skip` lands inside a wide grapheme, that whole cluster is
     * consumed (the partial half-cell is dropped) so the result
     * starts at a clean cell boundary.
     */
    public static function dropAnsi(string $s, int $skip): string
    {
        if ($skip <= 0) {
            return $s;
        }
        $len = strlen($s);
        $prefix = '';
        $tail   = '';
        $w = 0;
        $i = 0;
        $reachedTail = false;

        while ($i < $len) {
            $b = $s[$i];

            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $seq = substr($s, $i, $j - $i);
                if ($reachedTail) {
                    $tail .= $seq;
                } else {
                    $prefix .= $seq;
                }
                $i = $j;
                continue;
            }
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($s[$j] === "\x07") {
                        $j++;
                        break;
                    }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') {
                        $j += 2;
                        break;
                    }
                    $j++;
                }
                $seq = substr($s, $i, $j - $i);
                if ($reachedTail) {
                    $tail .= $seq;
                } else {
                    $prefix .= $seq;
                }
                $i = $j;
                continue;
            }

            $cluster = self::nextCluster($s, $i);
            if ($reachedTail) {
                $tail .= $cluster;
                $i += strlen($cluster);
                continue;
            }
            $gw = self::graphemeWidth($cluster);
            if ($w >= $skip) {
                // We're already past the skip budget — this cluster
                // belongs to the tail.
                $reachedTail = true;
                $tail .= $cluster;
                $w += $gw;
                $i += strlen($cluster);
                continue;
            }
            if ($w + $gw > $skip) {
                // Wide cluster straddles the boundary — drop it
                // entirely so the result starts at a clean cell.
                $reachedTail = true;
                $w += $gw;
                $i += strlen($cluster);
                continue;
            }
            $w += $gw;
            $i += strlen($cluster);
        }
        return $prefix . $tail;
    }

    /**
     * Take the first `$take` visible cells from `$s` and return them
     * (with all ANSI escape sequences that affect those cells preserved).
     *
     * Cell-aware counterpart to {@see dropAnsi()}: where `dropAnsi` returns
     * "everything after the first N cells", `takeAnsi` returns "the first N cells".
     * Both preserve the ANSI sequences so the result is self-contained.
     *
     * If `$take` lands inside a wide grapheme, that whole cluster is
     * included so the result is not silently truncated mid-character.
     */
    public static function takeAnsi(string $s, int $take): string
    {
        if ($take <= 0) {
            return '';
        }
        $len = strlen($s);
        $out  = '';
        $w    = 0;
        $i    = 0;
        $budgetReached = false;

        while ($i < $len) {
            $b = $s[$i];

            // Pass-through: CSI sequences (ESC [ ... final).
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $seq = substr($s, $i, $j - $i);
                $out .= $seq;
                $i = $j;
                continue;
            }
            // Pass-through: OSC sequences (ESC ] ... ST/BEL).
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($s[$j] === "\x07") {
                        $j++;
                        break;
                    }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') {
                        $j += 2;
                        break;
                    }
                    $j++;
                }
                $seq = substr($s, $i, $j - $i);
                $out .= $seq;
                $i = $j;
                continue;
            }

            // No more visible budget — keep scanning so trailing ANSI
            // sequences (e.g. SGR resets) get harvested by the loop above,
            // but skip visible characters silently.
            if ($budgetReached) {
                $cluster = self::nextCluster($s, $i);
                $i += strlen($cluster);
                continue;
            }

            $cluster = self::nextCluster($s, $i);
            $gw = self::graphemeWidth($cluster);
            if ($w + $gw > $take) {
                // Wide cluster straddles the boundary — include it whole
                // so we don't end mid-character.
                $out .= $cluster;
                $budgetReached = true;
                $i += strlen($cluster);
                continue;
            }
            $out .= $cluster;
            $w += $gw;
            $i += strlen($cluster);
            if ($w >= $take) {
                $budgetReached = true;
            }
        }
        return $out;
    }

    /**
     * The cluster starting at byte offset `$i`, via ICU's UAX #29 segmenter.
     *
     * **Public: this is the canonical cluster walk for the whole stack.**
     * sugar-toast's painter ran a verbatim copy of this body (the sanctioned
     * fork at `@c5cce07d7`) only because this method was `private`; the fork's
     * body is now deleted and the toast renderer delegates here, so one
     * splitter — including both invalid-UTF-8 guards — decides segmentation
     * everywhere. Keep the signature and the byte-for-byte reproduction of
     * malformed input stable: sibling libs now link against it.
     *
     * **The `function_exists()` fallback below is a SEAM, not a supported
     * path, and it is measurably wrong.** WHAT THE ABSENCE OF A NOTE HERE
     * IMPLIED: that the two branches are interchangeable. WHAT IS TRUE NOW:
     * they are not, and E73 widened the gap. The fallback splits per
     * CODEPOINT (a UTF-8 lead-byte length decode), so every codepoint of a
     * ZWJ sequence becomes its own "cluster" and is scored on its own. Before
     * E73, `compute()`'s ZWJ state machine happened to compensate for exactly
     * that; removing the machine — correct on the ICU path, where it only
     * ever fired on clusters UAX #29 had already decided did NOT join — left
     * this branch over-counting a joined sequence by 2 cells per extra emoji.
     *
     * Measured on PHP 8.3.6 under `-d disable_functions=grapheme_extract`
     * (`function_exists('grapheme_extract') === false` confirmed in the same
     * run), old class body vs new: U+1F469 ZWJ U+1F4BB scored 2 before and
     * scores 4 now; the four-person family U+1F468 ZWJ U+1F469 ZWJ U+1F467
     * ZWJ U+1F466 scored 2 before and scores 8 now — worst observed
     * regression 6 cells, in the OVER-count direction. The branch was already
     * wrong for Extend independently of E73: U+1F44D + U+1F3FD scores 4 here
     * on both bodies against ICU's 2.
     *
     * WHY IT STILL EARNS ITS PLACE: `sugar-core/composer.json` hard-requires
     * `ext-intl`, so nothing reachable executes it — it is latent, not live,
     * and it guarantees this class degrades to a defined answer rather than a
     * fatal if that requirement is ever relaxed. Anyone who does relax it
     * must fix this branch first; it is not merely approximate, it is wrong
     * by a bounded amount recorded above.
     */
    public static function nextCluster(string $s, int $i): string
    {
        if (function_exists('grapheme_extract')) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            // On malformed UTF-8 ICU does not return the bytes AT `$i`: it
            // skips a stray lead byte and hands back the NEXT cluster
            // (`"aaa\xffb"` at 3 yields `"b"`), or substitutes U+FFFD for a
            // truncated tail (`"ab\xc3"` at 2 yields 3 bytes of EF BF BD).
            // Every caller advances by strlen() of what comes back and
            // re-emits it as the input's own bytes, so trusting either answer
            // duplicated one cluster and dropped the bad byte —
            // truncate("aaa\xffb", 10) was "aaabb". Accept ICU's cluster only
            // when it IS the input at `$i`.
            if (is_string($cluster) && $cluster !== ''
                && substr_compare($s, $cluster, $i, strlen($cluster)) === 0
            ) {
                return $cluster;
            }
        }
        $b = ord($s[$i]);
        $bytes = match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
        // A lead byte only owns the bytes that really are continuations
        // (10xxxxxx); otherwise it is a stray byte of its own, so a broken
        // sequence like `\xe2AB` never swallows the ASCII after it.
        $len = strlen($s);
        for ($k = 1; $k < $bytes; $k++) {
            if ($i + $k >= $len || (ord($s[$i + $k]) & 0xc0) !== 0x80) {
                return $s[$i];
            }
        }
        return substr($s, $i, $bytes);
    }

    /**
     * Split `$s` into grapheme clusters — the SAME segmentation every
     * truncation path in this class already used via {@see nextCluster()}.
     *
     * This used to prefer `grapheme_str_split()`, which is PHP 8.4+, and fall
     * back to `mb_str_split()` on 8.3. That made `string()` measure per
     * CODEPOINT while `truncateAnsi()`/`takeAnsi()`/`dropAnsi()` measured per
     * CLUSTER, and the two disagreed on any multi-codepoint cluster that the
     * ZWJ special-case then living in `compute()` did not happen to
     * compensate — an emoji + skin-tone modifier being the common one. (That
     * special case is GONE as of E73; do not go looking for it in
     * {@see compute()}. This paragraph is history, kept because it is why
     * both sides walk one splitter.) The consequence was
     * E68: `truncateAnsi()` correctly stopped BEFORE a cluster it could not
     * fit, then the caller scored the result with `string()` and got a number
     * over the budget it had just asked for. Both sides now walk one function,
     * so `string(truncateAnsi($s, $n)) <= $n` holds by construction rather
     * than by the two measures coincidentally agreeing.
     *
     * @return list<string>
     */
    private static function graphemes(string $s): array
    {
        $len = strlen($s);
        $out = [];
        $i = 0;
        while ($i < $len) {
            $cluster = self::nextCluster($s, $i);
            if ($cluster === '') {
                // nextCluster() is contracted to return at least one byte;
                // this guards the loop against a future regression rather
                // than a reachable state.
                $cluster = $s[$i];
            }
            $out[] = $cluster;
            $i += strlen($cluster);
        }
        return $out;
    }

    private static function graphemeWidth(string $g): int
    {
        if ($g === '') {
            return 0;
        }
        $cp = self::firstCodepoint($g);
        if ($cp === 0) {
            return 0;
        }
        // A regional-indicator PAIR is a single flag glyph occupying 2 cells;
        // a lone regional indicator renders as a 1-cell letter box. Neither
        // codepoint is in the wide ranges below, so without this the pair
        // scored 1 as a cluster while the old per-codepoint sum scored 2 —
        // the +1 half of E68's over-run.
        if ($cp >= 0x1f1e6 && $cp <= 0x1f1ff) {
            return self::isRegionalIndicatorPair($g) ? 2 : 1;
        }
        // E69: a tab is laid out as {@see TAB_WIDTH} cells by every renderer
        // that consumes this measure, so it is charged that here. It must be
        // tested before isZeroWidth(), which swallows the whole C0 range.
        if ($cp === 0x09) {
            return self::TAB_WIDTH;
        }
        if (self::isZeroWidth($cp)) {
            return 0;
        }
        if (self::isWide($cp) || self::isEmojiPresentation($cp)) {
            return 2;
        }
        return 1;
    }

    /**
     * True when `$g` is a cluster whose first two codepoints are both
     * regional indicators — i.e. a flag. Each regional indicator is exactly
     * 4 bytes in UTF-8, so the second one starts at byte 4.
     */
    private static function isRegionalIndicatorPair(string $g): bool
    {
        if (strlen($g) < 8) {
            return false;
        }
        $second = self::firstCodepoint(substr($g, 4));
        return $second >= 0x1f1e6 && $second <= 0x1f1ff;
    }

    private static function firstCodepoint(string $g): int
    {
        if (function_exists('mb_ord')) {
            /** @var int|false $cp */
            $cp = mb_ord($g, 'UTF-8');
            return $cp === false ? 0 : $cp;
        }
        $b1 = ord($g[0]);
        if ($b1 < 0x80) {
            return $b1;
        }
        if (($b1 & 0xe0) === 0xc0 && strlen($g) >= 2) {
            return (($b1 & 0x1f) << 6) | (ord($g[1]) & 0x3f);
        }
        if (($b1 & 0xf0) === 0xe0 && strlen($g) >= 3) {
            return (($b1 & 0x0f) << 12) | ((ord($g[1]) & 0x3f) << 6) | (ord($g[2]) & 0x3f);
        }
        if (($b1 & 0xf8) === 0xf0 && strlen($g) >= 4) {
            return (($b1 & 0x07) << 18) | ((ord($g[1]) & 0x3f) << 12)
                 | ((ord($g[2]) & 0x3f) << 6) | (ord($g[3]) & 0x3f);
        }
        return 0;
    }

    /**
     * Zero-width combining spans from Unicode 16.0.0 DerivedGeneralCategory
     * (Mn + Me) that the fast ladder in {@see isZeroWidth()} does not already
     * cover, as flat ascending lo,hi pairs.
     *
     * The ladder keeps the common cases (the diacritic blocks, ZWJ/ZWNJ/ZWSP,
     * BOM, selectors, half-marks, C0/C1) as straight comparisons; everything
     * the SC-1 census found missing — Cyrillic combining titlos, the Hebrew
     * points, Arabic and Indic marks, Thai/Lao vowel signs, Tibetan, Myanmar,
     * the extended Latin/Indic planes, variation selectors above the BMP,
     * U+302A..U+302D and U+3099..U+309A — lives here and is found by binary
     * search, so the cost stays O(log n) no matter how much Unicode grows.
     *
     * Regenerate from the UCD with the lane script (merged runs, ladder spans
     * excluded, pairs sorted); {@see WidthCombiningDifferentialTest()} walks
     * the table's own membership from source, so a hand-edit that diverges
     * from the shipped ranges goes red, and ladder/table shadowing goes red
     * too. Mc spacing marks are deliberately absent: see WIDTH POLICY above.
     *
     * @var list<int>
     */
    private const COMBINING_RANGES = [
        0x0483, 0x0489, 0x0591, 0x05BD, 0x05BF, 0x05BF, 0x05C1, 0x05C2,
        0x05C4, 0x05C5, 0x05C7, 0x05C7, 0x0610, 0x061A, 0x064B, 0x065F,
        0x0670, 0x0670, 0x06D6, 0x06DC, 0x06DF, 0x06E4, 0x06E7, 0x06E8,
        0x06EA, 0x06ED, 0x0711, 0x0711, 0x0730, 0x074A, 0x07A6, 0x07B0,
        0x07EB, 0x07F3, 0x07FD, 0x07FD, 0x0816, 0x0819, 0x081B, 0x0823,
        0x0825, 0x0827, 0x0829, 0x082D, 0x0859, 0x085B, 0x0897, 0x089F,
        0x08CA, 0x08E1, 0x08E3, 0x0902, 0x093A, 0x093A, 0x093C, 0x093C,
        0x0941, 0x0948, 0x094D, 0x094D, 0x0951, 0x0957, 0x0962, 0x0963,
        0x0981, 0x0981, 0x09BC, 0x09BC, 0x09C1, 0x09C4, 0x09CD, 0x09CD,
        0x09E2, 0x09E3, 0x09FE, 0x09FE, 0x0A01, 0x0A02, 0x0A3C, 0x0A3C,
        0x0A41, 0x0A42, 0x0A47, 0x0A48, 0x0A4B, 0x0A4D, 0x0A51, 0x0A51,
        0x0A70, 0x0A71, 0x0A75, 0x0A75, 0x0A81, 0x0A82, 0x0ABC, 0x0ABC,
        0x0AC1, 0x0AC5, 0x0AC7, 0x0AC8, 0x0ACD, 0x0ACD, 0x0AE2, 0x0AE3,
        0x0AFA, 0x0AFF, 0x0B01, 0x0B01, 0x0B3C, 0x0B3C, 0x0B3F, 0x0B3F,
        0x0B41, 0x0B44, 0x0B4D, 0x0B4D, 0x0B55, 0x0B56, 0x0B62, 0x0B63,
        0x0B82, 0x0B82, 0x0BC0, 0x0BC0, 0x0BCD, 0x0BCD, 0x0C00, 0x0C00,
        0x0C04, 0x0C04, 0x0C3C, 0x0C3C, 0x0C3E, 0x0C40, 0x0C46, 0x0C48,
        0x0C4A, 0x0C4D, 0x0C55, 0x0C56, 0x0C62, 0x0C63, 0x0C81, 0x0C81,
        0x0CBC, 0x0CBC, 0x0CBF, 0x0CBF, 0x0CC6, 0x0CC6, 0x0CCC, 0x0CCD,
        0x0CE2, 0x0CE3, 0x0D00, 0x0D01, 0x0D3B, 0x0D3C, 0x0D41, 0x0D44,
        0x0D4D, 0x0D4D, 0x0D62, 0x0D63, 0x0D81, 0x0D81, 0x0DCA, 0x0DCA,
        0x0DD2, 0x0DD4, 0x0DD6, 0x0DD6, 0x0E31, 0x0E31, 0x0E34, 0x0E3A,
        0x0E47, 0x0E4E, 0x0EB1, 0x0EB1, 0x0EB4, 0x0EBC, 0x0EC8, 0x0ECE,
        0x0F18, 0x0F19, 0x0F35, 0x0F35, 0x0F37, 0x0F37, 0x0F39, 0x0F39,
        0x0F71, 0x0F7E, 0x0F80, 0x0F84, 0x0F86, 0x0F87, 0x0F8D, 0x0F97,
        0x0F99, 0x0FBC, 0x0FC6, 0x0FC6, 0x102D, 0x1030, 0x1032, 0x1037,
        0x1039, 0x103A, 0x103D, 0x103E, 0x1058, 0x1059, 0x105E, 0x1060,
        0x1071, 0x1074, 0x1082, 0x1082, 0x1085, 0x1086, 0x108D, 0x108D,
        0x109D, 0x109D, 0x135D, 0x135F, 0x1712, 0x1714, 0x1732, 0x1733,
        0x1752, 0x1753, 0x1772, 0x1773, 0x17B4, 0x17B5, 0x17B7, 0x17BD,
        0x17C6, 0x17C6, 0x17C9, 0x17D3, 0x17DD, 0x17DD, 0x180B, 0x180D,
        0x180F, 0x180F, 0x1885, 0x1886, 0x18A9, 0x18A9, 0x1920, 0x1922,
        0x1927, 0x1928, 0x1932, 0x1932, 0x1939, 0x193B, 0x1A17, 0x1A18,
        0x1A1B, 0x1A1B, 0x1A56, 0x1A56, 0x1A58, 0x1A5E, 0x1A60, 0x1A60,
        0x1A62, 0x1A62, 0x1A65, 0x1A6C, 0x1A73, 0x1A7C, 0x1A7F, 0x1A7F,
        0x1B00, 0x1B03, 0x1B34, 0x1B34, 0x1B36, 0x1B3A, 0x1B3C, 0x1B3C,
        0x1B42, 0x1B42, 0x1B6B, 0x1B73, 0x1B80, 0x1B81, 0x1BA2, 0x1BA5,
        0x1BA8, 0x1BA9, 0x1BAB, 0x1BAD, 0x1BE6, 0x1BE6, 0x1BE8, 0x1BE9,
        0x1BED, 0x1BED, 0x1BEF, 0x1BF1, 0x1C2C, 0x1C33, 0x1C36, 0x1C37,
        0x1CD0, 0x1CD2, 0x1CD4, 0x1CE0, 0x1CE2, 0x1CE8, 0x1CED, 0x1CED,
        0x1CF4, 0x1CF4, 0x1CF8, 0x1CF9, 0x2CEF, 0x2CF1, 0x2D7F, 0x2D7F,
        0x2DE0, 0x2DFF, 0x302A, 0x302D, 0x3099, 0x309A, 0xA66F, 0xA672,
        0xA674, 0xA67D, 0xA69E, 0xA69F, 0xA6F0, 0xA6F1, 0xA802, 0xA802,
        0xA806, 0xA806, 0xA80B, 0xA80B, 0xA825, 0xA826, 0xA82C, 0xA82C,
        0xA8C4, 0xA8C5, 0xA8E0, 0xA8F1, 0xA8FF, 0xA8FF, 0xA926, 0xA92D,
        0xA947, 0xA951, 0xA980, 0xA982, 0xA9B3, 0xA9B3, 0xA9B6, 0xA9B9,
        0xA9BC, 0xA9BD, 0xA9E5, 0xA9E5, 0xAA29, 0xAA2E, 0xAA31, 0xAA32,
        0xAA35, 0xAA36, 0xAA43, 0xAA43, 0xAA4C, 0xAA4C, 0xAA7C, 0xAA7C,
        0xAAB0, 0xAAB0, 0xAAB2, 0xAAB4, 0xAAB7, 0xAAB8, 0xAABE, 0xAABF,
        0xAAC1, 0xAAC1, 0xAAEC, 0xAAED, 0xAAF6, 0xAAF6, 0xABE5, 0xABE5,
        0xABE8, 0xABE8, 0xABED, 0xABED, 0xFB1E, 0xFB1E, 0x101FD, 0x101FD,
        0x102E0, 0x102E0, 0x10376, 0x1037A, 0x10A01, 0x10A03, 0x10A05, 0x10A06,
        0x10A0C, 0x10A0F, 0x10A38, 0x10A3A, 0x10A3F, 0x10A3F, 0x10AE5, 0x10AE6,
        0x10D24, 0x10D27, 0x10D69, 0x10D6D, 0x10EAB, 0x10EAC, 0x10EFC, 0x10EFF,
        0x10F46, 0x10F50, 0x10F82, 0x10F85, 0x11001, 0x11001, 0x11038, 0x11046,
        0x11070, 0x11070, 0x11073, 0x11074, 0x1107F, 0x11081, 0x110B3, 0x110B6,
        0x110B9, 0x110BA, 0x110C2, 0x110C2, 0x11100, 0x11102, 0x11127, 0x1112B,
        0x1112D, 0x11134, 0x11173, 0x11173, 0x11180, 0x11181, 0x111B6, 0x111BE,
        0x111C9, 0x111CC, 0x111CF, 0x111CF, 0x1122F, 0x11231, 0x11234, 0x11234,
        0x11236, 0x11237, 0x1123E, 0x1123E, 0x11241, 0x11241, 0x112DF, 0x112DF,
        0x112E3, 0x112EA, 0x11300, 0x11301, 0x1133B, 0x1133C, 0x11340, 0x11340,
        0x11366, 0x1136C, 0x11370, 0x11374, 0x113BB, 0x113C0, 0x113CE, 0x113CE,
        0x113D0, 0x113D0, 0x113D2, 0x113D2, 0x113E1, 0x113E2, 0x11438, 0x1143F,
        0x11442, 0x11444, 0x11446, 0x11446, 0x1145E, 0x1145E, 0x114B3, 0x114B8,
        0x114BA, 0x114BA, 0x114BF, 0x114C0, 0x114C2, 0x114C3, 0x115B2, 0x115B5,
        0x115BC, 0x115BD, 0x115BF, 0x115C0, 0x115DC, 0x115DD, 0x11633, 0x1163A,
        0x1163D, 0x1163D, 0x1163F, 0x11640, 0x116AB, 0x116AB, 0x116AD, 0x116AD,
        0x116B0, 0x116B5, 0x116B7, 0x116B7, 0x1171D, 0x1171D, 0x1171F, 0x1171F,
        0x11722, 0x11725, 0x11727, 0x1172B, 0x1182F, 0x11837, 0x11839, 0x1183A,
        0x1193B, 0x1193C, 0x1193E, 0x1193E, 0x11943, 0x11943, 0x119D4, 0x119D7,
        0x119DA, 0x119DB, 0x119E0, 0x119E0, 0x11A01, 0x11A0A, 0x11A33, 0x11A38,
        0x11A3B, 0x11A3E, 0x11A47, 0x11A47, 0x11A51, 0x11A56, 0x11A59, 0x11A5B,
        0x11A8A, 0x11A96, 0x11A98, 0x11A99, 0x11C30, 0x11C36, 0x11C38, 0x11C3D,
        0x11C3F, 0x11C3F, 0x11C92, 0x11CA7, 0x11CAA, 0x11CB0, 0x11CB2, 0x11CB3,
        0x11CB5, 0x11CB6, 0x11D31, 0x11D36, 0x11D3A, 0x11D3A, 0x11D3C, 0x11D3D,
        0x11D3F, 0x11D45, 0x11D47, 0x11D47, 0x11D90, 0x11D91, 0x11D95, 0x11D95,
        0x11D97, 0x11D97, 0x11EF3, 0x11EF4, 0x11F00, 0x11F01, 0x11F36, 0x11F3A,
        0x11F40, 0x11F40, 0x11F42, 0x11F42, 0x11F5A, 0x11F5A, 0x13440, 0x13440,
        0x13447, 0x13455, 0x1611E, 0x16129, 0x1612D, 0x1612F, 0x16AF0, 0x16AF4,
        0x16B30, 0x16B36, 0x16F4F, 0x16F4F, 0x16F8F, 0x16F92, 0x16FE4, 0x16FE4,
        0x1BC9D, 0x1BC9E, 0x1CF00, 0x1CF2D, 0x1CF30, 0x1CF46, 0x1D167, 0x1D169,
        0x1D17B, 0x1D182, 0x1D185, 0x1D18B, 0x1D1AA, 0x1D1AD, 0x1D242, 0x1D244,
        0x1DA00, 0x1DA36, 0x1DA3B, 0x1DA6C, 0x1DA75, 0x1DA75, 0x1DA84, 0x1DA84,
        0x1DA9B, 0x1DA9F, 0x1DAA1, 0x1DAAF, 0x1E000, 0x1E006, 0x1E008, 0x1E018,
        0x1E01B, 0x1E021, 0x1E023, 0x1E024, 0x1E026, 0x1E02A, 0x1E08F, 0x1E08F,
        0x1E130, 0x1E136, 0x1E2AE, 0x1E2AE, 0x1E2EC, 0x1E2EF, 0x1E4EC, 0x1E4EF,
        0x1E5EE, 0x1E5EF, 0x1E8D0, 0x1E8D6, 0x1E944, 0x1E94A, 0xE0100, 0xE01EF,
    ];

    /**
     * Binary search over {@see COMBINING_RANGES}: true when $cp falls inside
     * one [lo, hi] pair. The table is sorted and disjoint by construction.
     */
    private static function inCombiningRanges(int $cp): bool
    {
        $table = self::COMBINING_RANGES;
        $low = 0;
        $high = (int) (\count($table) / 2) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($cp < $table[$mid * 2]) {
                $high = $mid - 1;
            } elseif ($cp > $table[$mid * 2 + 1]) {
                $low = $mid + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    private static function isZeroWidth(int $cp): bool
    {
        if ($cp < 0x20) {
            return true;
        }
        if ($cp >= 0x7f && $cp < 0xa0) {
            return true;
        }
        if ($cp === 0x200b || $cp === 0x200c || $cp === 0x200d || $cp === 0xfeff) {
            return true;
        }
        if ($cp >= 0x0300 && $cp <= 0x036f) {
            return true;
        }
        if ($cp >= 0x1ab0 && $cp <= 0x1aff) {
            return true;
        }
        if ($cp >= 0x1dc0 && $cp <= 0x1dff) {
            return true;
        }
        if ($cp >= 0x20d0 && $cp <= 0x20ff) {
            return true;
        }
        if ($cp >= 0xfe00 && $cp <= 0xfe0f) {
            return true;
        }
        if ($cp >= 0xfe20 && $cp <= 0xfe2f) {
            return true;
        }
        return self::inCombiningRanges($cp);
    }

    /**
     * East Asian WIDE (EAW W/F) charge of two cells.
     *
     * The leading ladder is Markus Kuhn's 2006 wcwidth block set, kept
     * verbatim except one head fix: U+FF00 is unassigned, so the fullwidth
     * leg starts at U+FF01, not U+FF00. The halfwidth forms U+FF61..U+FFDC
     * and U+FFE8..U+FFEE were never inside these legs (the review snapshot's
     * 0xFF01..0xFFEE claim was stale) and still measure 1.
     *
     * The 2026-11 SC-1 census appended every remaining Unicode 16.0 EAW-W run
     * the 2006 set predates, as exact boundaries: the Yijing hexagrams and
     * counting rod numerals, the trigram and die-face symbol slots, Shan
     * tone marks, the Vertical Forms (U+FE10..U+FE19) and Small Form
     * Derivatives blocks, the Tangut/Nushu/Khitan/Jurchen plane-1 scripts,
     * the Hangul Jamo Extended-B and Hangul Extended-A/B slots, and the
     * Tai Xuan Jing counts. Holes inside those NEW legs (U+FE53, U+FE67,
     * U+1AFF4, U+1AFFC, all Cn) stay 1 — see WIDTH POLICY in the class
     * docblock. Mn members that fall inside a leg (U+16FE4, and the
     * in-block combining marks U+302A..U+302D and U+3099..U+309A) are
     * charged 0 by the zero-wins-over-wide precedence in graphemeWidth().
     */
    private static function isWide(int $cp): bool
    {
        if ($cp < 0x1100) {
            return false;
        }
        // At this point we know $cp >= 0x1100
        return ($cp <= 0x115f)
            || ($cp >= 0x2329 && $cp <= 0x232a)
            || ($cp >= 0x2630 && $cp <= 0x2637)
            || ($cp >= 0x268a && $cp <= 0x268f)
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3041 && $cp <= 0x33ff)
            || ($cp >= 0x3400 && $cp <= 0x4dbf)
            || ($cp >= 0x4dc0 && $cp <= 0x4dff)
            || ($cp >= 0x4e00 && $cp <= 0x9fff)
            || ($cp >= 0xa000 && $cp <= 0xa4cf)
            || ($cp >= 0xa960 && $cp <= 0xa97c)
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe10 && $cp <= 0xfe19)
            || ($cp >= 0xfe30 && $cp <= 0xfe4f)
            || ($cp >= 0xfe50 && $cp <= 0xfe52)
            || ($cp >= 0xfe54 && $cp <= 0xfe66)
            || ($cp >= 0xfe68 && $cp <= 0xfe6b)
            || ($cp >= 0xff01 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x16fe0 && $cp <= 0x16fe3)
            || ($cp >= 0x16ff0 && $cp <= 0x16ff1)
            || ($cp >= 0x17000 && $cp <= 0x187f7)
            || ($cp >= 0x18800 && $cp <= 0x18cd5)
            || ($cp >= 0x18cff && $cp <= 0x18d08)
            || ($cp >= 0x1aff0 && $cp <= 0x1aff3)
            || ($cp >= 0x1aff5 && $cp <= 0x1affb)
            || ($cp >= 0x1affd && $cp <= 0x1affe)
            || ($cp >= 0x1b000 && $cp <= 0x1b122)
            || ($cp === 0x1b132)
            || ($cp >= 0x1b150 && $cp <= 0x1b152)
            || ($cp === 0x1b155)
            || ($cp >= 0x1b164 && $cp <= 0x1b167)
            || ($cp >= 0x1b170 && $cp <= 0x1b2fb)
            || ($cp >= 0x1d300 && $cp <= 0x1d356)
            || ($cp >= 0x1d360 && $cp <= 0x1d376)
            || ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }

    /**
     * Codepoints pinned to the Emoji_Presentation=Yes set of Unicode 16.0's
     * emoji-data.txt: they render as full-colour emoji — TWO cells — by
     * default in every modern terminal, whether or not their
     * East_Asian_Width property is Wide.
     *
     * KNOWN LAG: newer Unicode releases may add Emoji_Presentation=Yes
     * codepoints this snapshot does not carry; the boundary rows in
     * sugar-core/tests/Util/WidthTest.php are the tripwire that keeps the
     * roster honest to the release it claims.
     *
     * PRESENTATION SELECTORS ARE NOT CONSULTED: the rule is first-codepoint,
     * so U+FE0E/U+FE0F never reach this predicate as the scored character —
     * U+231A followed by U+FE0E (text-style watch) over-counts as 2, and
     * U+2764 followed by U+FE0F (red heart) under-counts as 1. Declared
     * limitation class; cluster-level selector handling would rewrite
     * compute()'s grapheme walk for two glyphs nobody pastes.
     *
     * WHY THIS IS NOT FOLDED INTO {@see isWide()}: those tables are the
     * classic block-shaped East-Asian ranges (Markus Kuhn's 2006 set), and
     * the members here mostly carry East_Asian_Width=Neutral — a different
     * axis of the spec. Keeping the rule separate preserves isWide()'s
     * meaning (and the endpoint corpus in tests/Util/WidthTest.php that
     * mirrors its ranges) while letting graphemeWidth() charge the union,
     * which is what the terminal lays out. This is the shape
     * {@see isEmoji()}'s docblock anticipated: the width-relevant emoji
     * predicate, now with the callers it was reserved for.
     *
     * THE INCIDENT: a live "💭 Thinking…" row padded to exactly the pane
     * width counted its ✅ (U+2705) as one cell, the terminal gave it two,
     * and the wrap slid every border on and below it one column left — the
     * ragged right edge of a user report. Measured against PHP 8.3's
     * `mb_strwidth` and the terminal consensus; roster and pins live in
     * sugar-core/tests/Util/WidthTest.php.
     *
     * THE SC-1 CENSUS (2026-11) verified this roster EXACT against
     *     Unicode 16.0.0 emoji-data.txt — zero Emoji_Presentation
     *     disagreements across the full codepoint range.
     *
     * SCOPE NOTES:
     * - U+2329/232A angle brackets are EAW-W (not emoji) and are charged 2 by
     *   isWide() as of the SC-1 census; they were never this table's business.
     * - U+1F1E6..U+1F1FF regional indicators — paired flags are already
     *   scored by {@see isRegionalIndicatorPair()} at the cluster level;
     * - the former "non-emoji scripts whose EAW-W isWide() misses" gap
     *   (Tangut, Nushu, Hangul Ext-B, small-form variants) is CLOSED: this
     *   lane added the exact Unicode-16 W legs to isWide() instead of
     *   smuggling them through the emoji table.
     */
    private static function isEmojiPresentation(int $cp): bool
    {
        // BMP emoji-presentation members, grouped as Unicode blocks list them.
        if ($cp <= 0xFFFF) {
            return ($cp >= 0x231A && $cp <= 0x231B)
                || ($cp >= 0x23E9 && $cp <= 0x23EC)
                || $cp === 0x23F0
                || $cp === 0x23F3
                || ($cp >= 0x25FD && $cp <= 0x25FE)
                || ($cp >= 0x2614 && $cp <= 0x2615)
                || ($cp >= 0x2648 && $cp <= 0x2653)
                || $cp === 0x267F
                || $cp === 0x2693
                || $cp === 0x26A1
                || ($cp >= 0x26AA && $cp <= 0x26AB)
                || ($cp >= 0x26BD && $cp <= 0x26BE)
                || ($cp >= 0x26C4 && $cp <= 0x26C5)
                || $cp === 0x26CE
                || $cp === 0x26D4
                || $cp === 0x26EA
                || ($cp >= 0x26F2 && $cp <= 0x26F3)
                || $cp === 0x26F5
                || $cp === 0x26FA
                || $cp === 0x26FD
                || $cp === 0x2705
                || ($cp >= 0x270A && $cp <= 0x270B)
                || $cp === 0x2728
                || $cp === 0x274C
                || $cp === 0x274E
                || ($cp >= 0x2753 && $cp <= 0x2755)
                || $cp === 0x2757
                || ($cp >= 0x2795 && $cp <= 0x2797)
                || $cp === 0x27B0
                || $cp === 0x27BF
                || ($cp >= 0x2B1B && $cp <= 0x2B1C)
                || $cp === 0x2B50
                || $cp === 0x2B55;
        }

        // Astral members outside the ranges isWide() already covers whole.
        // The 1FA70+ block merges the Unicode 16.0 additions into the ranges
        // they extend — every seam is EP=Yes on both sides, so the ranges stay
        // an exact cover of the pinned set (boundary rows in WidthTest prove
        // neither creep nor gap).
        return $cp === 0x1F004
            || $cp === 0x1F0CF
            || $cp === 0x1F18E
            || ($cp >= 0x1F191 && $cp <= 0x1F19A)
            || ($cp >= 0x1F200 && $cp <= 0x1F202)
            || ($cp >= 0x1F210 && $cp <= 0x1F23B)
            || ($cp >= 0x1F240 && $cp <= 0x1F248)
            || ($cp >= 0x1F250 && $cp <= 0x1F251)
            || ($cp >= 0x1F260 && $cp <= 0x1F265)
            || ($cp >= 0x1F7E0 && $cp <= 0x1F7EB)
            || $cp === 0x1F7F0
            || ($cp >= 0x1FA70 && $cp <= 0x1FA7C)
            || ($cp >= 0x1FA80 && $cp <= 0x1FA89)
            || ($cp >= 0x1FA8F && $cp <= 0x1FABF)
            || ($cp >= 0x1FAC0 && $cp <= 0x1FAC6)
            || ($cp >= 0x1FACE && $cp <= 0x1FADC)
            || ($cp >= 0x1FADF && $cp <= 0x1FAE9)
            || ($cp >= 0x1FAF0 && $cp <= 0x1FAF8);
    }

    /**
     * Emoji-block membership. **Dormant seam — no caller since E73.**
     *
     * WHAT IT WAS FOR: `compute()`'s removed ZWJ state machine called this
     * twice — once on the codepoint BEFORE a ZWJ and once on the codepoint
     * after — to decide which neighbours of a bare ZWJ cluster were emoji it
     * should re-score. E73 deleted that machine (see {@see compute()}), which
     * left this with zero callers repo-wide.
     *
     * WHY IT IS KEPT rather than deleted: it is the only "is this codepoint an
     * emoji" predicate in the class, and a future emoji-presentation rule
     * (VS16 promotion, say) wants exactly this shape.
     *
     * WHY IT IS NOT WIRED INTO {@see graphemeWidth()}: it is NOT a width
     * predicate and must not be mistaken for one. The three ranges it covers
     * that {@see isWide()} does not are mostly NARROW. Measured against ICU
     * 74.2 / Unicode 15.1 via `IntlChar::PROPERTY_EAST_ASIAN_WIDTH`, counting
     * assigned codepoints only: U+1FA00..U+1FAFF is 107 Wide/Fullwidth vs 98
     * other, U+2600..U+26FF is 31 vs 225, U+2700..U+27BF is 15 vs 177.
     * Charging 2 cells for every codepoint this returns true for would
     * over-count 500 assigned codepoints and put the renderer in the
     * frame-corrupting direction.
     *
     * PHPStan reports this as `method.unused` at level 5; that is accurate and
     * is silenced by an `ignoreErrors` entry in `sugar-core/phpstan.neon`
     * naming this file, alongside the same treatment already given to
     * `src/Concerns/Mutable.php`.
     */
    private static function isEmoji(int $cp): bool
    {
        if ($cp < 0x1100) {
            return false;
        }
        return ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x1fa00 && $cp <= 0x1faff)
            || ($cp >= 0x2600 && $cp <= 0x26ff)
            || ($cp >= 0x2700 && $cp <= 0x27bf);
    }
}

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
     * E69: `string()` scored a tab 0 while `\SugarCraft\Sprinkles\Style::render()`
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
     * in `candy-sprinkles/tests/StyleTest.php`, which exercises the knob itself
     * (2, 0, and 8-then-unset) rather than any `Width` agreement. A `Style`
     * given `tabWidth(0)` (literal tabs) or any other non-default width still
     * disagrees with `string()`; that residue is deliberate and recorded rather
     * than papered over.
     *
     * Not to be confused with `\SugarCraft\Dash\Components\Card\Highlight`'s
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
     * WHY THE REMOVAL EARNS ITS PLACE: the machine's two clauses had become
     * pure loss. `Width::string("\t" . ZWJ . U+1F44D)` scored 0 for a run
     * `Style::render()` lays out as 6 cells (E73, measured on PHP 8.3.6 with ext-intl
     * ICU 74.2 / Unicode 15.1): the look-ahead zeroed the tab and the
     * flag zeroed the emoji.
     * Under-counting is the frame-corrupting direction here — the diff
     * renderer paints one line per terminal row — so this is not a tidy-up.
     */
    private static function compute(string $s): int
    {
        $clean = Ansi::strip($s);
        if ($clean === '') {
            return 0;
        }
        $width = 0;
        foreach (self::graphemes($clean) as $g) {
            $width += self::graphemeWidth($g);
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

            if ($b === "\n") {
                $line .= $word;
                $lineWidth += $wordWidth;
                $word = '';
                $wordWidth = 0;
                $flushLine();
                $i++;
                continue;
            }

            if ($b === ' ' || $b === "\t") {
                if ($word !== '') {
                    if ($lineWidth + $wordWidth > $max && $line !== '') {
                        $flushLine();
                    }
                    $line .= $word;
                    $lineWidth += $wordWidth;
                    $word = '';
                    $wordWidth = 0;
                }
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
                if ($word !== '') {
                    $line .= $word;
                    $lineWidth += $wordWidth;
                    $word = '';
                    $wordWidth = 0;
                }
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
        if ($word !== '') {
            if ($lineWidth + $wordWidth > $max && $line !== '') {
                $flushLine();
            }
            $line .= $word;
        }
        if ($line !== '') {
            $lines[] = rtrim($line);
        }
        return implode("\n", $lines);
    }

    private static function wrapParagraph(string $s, int $max): string
    {
        $words = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
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
     * the primitive {@see \SugarCraft\Sprinkles\Canvas} uses to
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

    private static function nextCluster(string $s, int $i): string
    {
        if (function_exists('grapheme_extract')) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (is_string($cluster) && $cluster !== '') {
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
     * ZWJ special-case in {@see compute()} did not happen to compensate — an
     * emoji + skin-tone modifier being the common one. The consequence was
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
        if (self::isWide($cp)) {
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
        return false;
    }

    private static function isWide(int $cp): bool
    {
        if ($cp < 0x1100) {
            return false;
        }
        // At this point we know $cp >= 0x1100
        return ($cp <= 0x115f)
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3041 && $cp <= 0x33ff)
            || ($cp >= 0x3400 && $cp <= 0x4dbf)
            || ($cp >= 0x4e00 && $cp <= 0x9fff)
            || ($cp >= 0xa000 && $cp <= 0xa4cf)
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe30 && $cp <= 0xfe4f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x1f300 && $cp <= 0x1f64f)
            || ($cp >= 0x1f680 && $cp <= 0x1f6ff)
            || ($cp >= 0x1f900 && $cp <= 0x1f9ff)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }

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

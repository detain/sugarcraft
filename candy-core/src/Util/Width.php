<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Display-width measurement for terminal text.
 *
 * Width is counted in monospace cells. ANSI escape sequences are stripped
 * before measurement. Wide East-Asian characters and most emoji count as 2;
 * zero-width and combining marks count as 0.
 */
final class Width
{
    /**
     * Cell width of a string after stripping ANSI sequences.
     */
    public static function string(string $s): int
    {
        $clean = Ansi::strip($s);
        if ($clean === '') {
            return 0;
        }
        $width = 0;
        $clusters = self::graphemes($clean);
        foreach ($clusters as $g) {
            $width += self::graphemeWidth($g);
        }
        return $width;
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
                    if ($s[$j] === "\x07") { $j++; break; }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') { $j += 2; break; }
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
                if ($lineWidth + 1 <= $max) {
                    $line .= $b;
                    $lineWidth += 1;
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
                    if ($s[$j] === "\x07") { $j++; break; }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') { $j += 2; break; }
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
                    if ($s[$j] === "\x07") { $j++; break; }
                    if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') { $j += 2; break; }
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

    /** @return list<string> */
    private static function graphemes(string $s): array
    {
        if (function_exists('grapheme_str_split')) {
            $g = grapheme_str_split($s);
            if (is_array($g)) {
                return $g;
            }
        }
        if (function_exists('mb_str_split')) {
            return mb_str_split($s, 1, 'UTF-8');
        }
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
        if (self::isZeroWidth($cp)) {
            return 0;
        }
        if (self::isWide($cp)) {
            return 2;
        }
        return 1;
    }

    private static function firstCodepoint(string $g): int
    {
        if (function_exists('mb_ord')) {
            /** @var int|false $cp */
            $cp = mb_ord($g, 'UTF-8');
            return $cp === false ? 0 : $cp;
        }
        $b1 = ord($g[0]);
        if ($b1 < 0x80) return $b1;
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
        if ($cp >= 0x0300 && $cp <= 0x036f) return true;
        if ($cp >= 0x1ab0 && $cp <= 0x1aff) return true;
        if ($cp >= 0x1dc0 && $cp <= 0x1dff) return true;
        if ($cp >= 0x20d0 && $cp <= 0x20ff) return true;
        if ($cp >= 0xfe00 && $cp <= 0xfe0f) return true;
        if ($cp >= 0xfe20 && $cp <= 0xfe2f) return true;
        return false;
    }

    private static function isWide(int $cp): bool
    {
        if ($cp < 0x1100) return false;
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
}

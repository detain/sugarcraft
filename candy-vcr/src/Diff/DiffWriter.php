<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Diff;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Vcr\Format\AtomicFileWriter;

/**
 * Writes annotated diff output in unified diff format to a file.
 *
 * When a replay assertion fails, this class generates a unified diff
 * (expected vs actual output) with real LCS-quality hunk placement
 * (Myers 1986 — a single moved/prepended line marks exactly one hunk,
 * finding 027) and a colour-coded side-by-side ANSI report whose rows
 * are never raw recorded bytes (finding 106: recorded terminal output
 * carries live escape sequences; printing them into a report or a .diff
 * file lets a cassette pwn the operator's terminal and pad the columns
 * out of alignment).
 *
 * Example:
 * ```php
 * $writer = new DiffWriter();
 * $writer->writeUnifiedDiff('/tmp/replay.diff', $expected, $actual);
 * ```
 */
final class DiffWriter
{
    /** Display-column cap for one report row (finding 114: one 10 KB minified line must not pad every row to 10 KB). */
    public const MAX_ROW_WIDTH = 160;

    /** Row cap per side of the ANSI report; overflow prints a '… +N lines' footer. */
    public const MAX_REPORT_ROWS = 400;

    /**
     * Edit-script ceiling for the Myers walk (O((N+M)D)). Beyond this the
     * inputs are not "two screens that drifted" but wholesale rewrites
     * where any hunk layout is arbitrary anyway — fail named instead of
     * burning memory on a trace nobody reads.
     */
    private const MAX_DIFF_EDITS = 20000;

    /**
     * Write a unified diff to a file.
     *
     * The unified diff is real LCS-placed unified format — `diff -u`
     * compatible, proven by applying fixtures with GNU `patch` in the
     * test suite (finding 027 retired that claim when it was still
     * positional). Bytes land atomically (see {@see AtomicFileWriter}).
     *
     * @param string $path Output file path
     * @param string $expected Expected output (from cassette)
     * @param string $actual Actual output (from program)
     * @param int $context Number of context lines around changes (default: 3)
     * @return int|false Number of bytes written, or false on failure
     */
    public function writeUnifiedDiff(
        string $path,
        string $expected,
        string $actual,
        int $context = 3,
    ): int|false {
        $diff = $this->buildUnifiedDiff($expected, $actual, $context);
        try {
            // Same atomic placement the cassette writers gained (finding
            // 018): a diff written during a failing CI run must not land
            // half-mangled. The documented int|false contract is kept.
            AtomicFileWriter::write($path, $diff, 'diff');
        } catch (\RuntimeException) {
            return false;
        }
        return strlen($diff);
    }

    /**
     * Build a unified diff string from expected and actual output.
     *
     * @param string $expected Expected output (from cassette)
     * @param string $actual Actual output (from program)
     * @param int $context Number of context lines around changes
     * @return string Unified diff string
     */
    public function buildUnifiedDiff(string $expected, string $actual, int $context = 3): string
    {
        [$expectedLines, $actualLines] = $this->splitBoth($expected, $actual);

        return $this->formatUnifiedDiff($expectedLines, $actualLines, $context);
    }

    /**
     * Write a colored ANSI diff suitable for terminal output.
     *
     * Layout: plain '--- EXPECTED ---' / '--- ACTUAL ---' dash rules, then
     * one row per line carrying a ' ' (same) or '!' (differing) text cue.
     * The color (green/red) is decoration — the difference signal is
     * legible without SGR, so it survives colour-blind viewers, NO_COLOR
     * piping and mono terminals.
     *
     * Rows carry ESCAPED content (never the recorded raw bytes: an ESC in
     * a report row used to clear the operator's screen mid-diff, finding
     * 106) at a capped column width, and at most {@see MAX_REPORT_ROWS}
     * rows per side with a '… +N lines' footer (finding 114).
     *
     * @param string $expected Expected output (from cassette)
     * @param string $actual Actual output (from program)
     * @param resource $output Output stream (default: STDOUT)
     */
    public function writeAnsiDiff(string $expected, string $actual, $output = STDOUT): void
    {
        [$expectedLines, $actualLines] = $this->splitBoth($expected, $actual);

        // Escape FIRST, then measure: the width census runs over what will
        // actually be printed, so strlen is display width again (control
        // bytes are printable backslash sequences by then — the old
        // ESC-bearing lines padded the columns out of alignment, 106).
        $expRows = array_map($this->escapeDiffLine(...), $expectedLines);
        $actRows = array_map($this->escapeDiffLine(...), $actualLines);

        // The '--- EXPECTED ---' rule is the report's minimum width; anything
        // narrower would have hand-padded the rule with a negative dash
        // repeat (the same crash-shape the old box header guarded).
        $maxWidth = 12;
        foreach ([$expRows, $actRows] as $rows) {
            foreach ($rows as $row) {
                $maxWidth = max($maxWidth, strlen($row));
            }
        }
        // Content field is prefix + space + padded row; every row (rules
        // included) lands on exactly $fieldWidth + 2 columns. The cap keeps
        // one minified line from fattening every row of the report (114).
        $fieldWidth = min(max($maxWidth, 12), self::MAX_ROW_WIDTH);
        $totalWidth = $fieldWidth + 2;
        $rule = static fn (string $label): string =>
            str_pad("--- {$label} ", $totalWidth, '-', STR_PAD_RIGHT);

        $maxLines = max(count($expRows), count($actRows));
        $shown = min($maxLines, self::MAX_REPORT_ROWS);

        fwrite($output, "\n");
        fwrite($output, Ansi::sgr(1, 33) . $rule('EXPECTED') . Ansi::reset() . "\n"); // Yellow rule, expected header

        for ($i = 0; $i < $shown; $i++) {
            $expLine = $this->ellipsize($expRows[$i] ?? '', $fieldWidth);
            $actLine = $this->ellipsize($actRows[$i] ?? '', $fieldWidth);
            $isSame = ($expectedLines[$i] ?? '') === ($actualLines[$i] ?? '');
            $color = $isSame ? Ansi::sgr(32) : Ansi::sgr(31); // Green for same, Red for diff
            // The '!' text cue rides the row so same-vs-differing never
            // depends on color alone (colour-blind and mono terminals).
            $prefix = $isSame ? ' ' : '!';
            fwrite($output, sprintf(
                "%s%s %-{$fieldWidth}s" . Ansi::reset() . "\n",
                $color,
                $prefix,
                $expLine,
            ));
        }
        $this->writeOverflowFooter($output, count($expRows), $shown);

        fwrite($output, "\n");
        fwrite($output, Ansi::sgr(1, 36) . $rule('ACTUAL') . Ansi::reset() . "\n"); // Cyan rule, actual header

        for ($i = 0; $i < $shown; $i++) {
            $actLine = $this->ellipsize($actRows[$i] ?? '', $fieldWidth);
            $isSame = ($expectedLines[$i] ?? '') === ($actualLines[$i] ?? '');
            $color = $isSame ? Ansi::sgr(32) : Ansi::sgr(31);
            $prefix = $isSame ? ' ' : '!';
            fwrite($output, sprintf(
                "%s%s %-{$fieldWidth}s" . Ansi::reset() . "\n",
                $color,
                $prefix,
                $actLine,
            ));
        }
        $this->writeOverflowFooter($output, count($actRows), $shown);
        fwrite($output, "\n");
    }

    /**
     * Normalize line endings to LF and split, then drop the single shared
     * trailing empty element both newline-terminated inputs explode to —
     * a trailing newline terminates the last line, it is not a blank
     * extra line (matching how `diff -u` counts file lines).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitBoth(string $expected, string $actual): array
    {
        $expectedLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $expected));
        $actualLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $actual));

        if ($expectedLines !== [] && end($expectedLines) === ''
            && $actualLines !== [] && end($actualLines) === ''
            && count($expectedLines) > 1 && count($actualLines) > 1
        ) {
            array_pop($expectedLines);
            array_pop($actualLines);
        }

        return [$expectedLines, $actualLines];
    }

    /**
     * Note the clipped rows instead of silently truncating the report.
     *
     * @param resource $output
     */
    private function writeOverflowFooter($output, int $total, int $shown): void
    {        if ($total > $shown) {
            fwrite($output, sprintf("  … +%d lines\n", $total - $shown));
        }
    }

    /**
     * Cap one display row, cutting on a UTF-8 byte boundary and marking
     * the cut with a single ellipsis character.
     */
    private function ellipsize(string $row, int $width): string
    {
        if (strlen($row) <= $width) {
            return $row;
        }
        // '…' is one display column but three UTF-8 bytes, and every
        // measurement here is strlen — reserve its byte cost so the cut
        // row lands INSIDE the field (a wider result would silently
        // break the "every row lands on fieldWidth + 2" alignment law).
        $cut = max(0, $width - 3);
        while ($cut > 0 && ((ord($row[$cut]) & 0xC0) === 0x80)) {
            $cut--; // step back off a multibyte continuation byte
        }
        return substr($row, 0, $cut) . '…';
    }

    /**
     * Format the diff of two line lists into a unified diff string.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function formatUnifiedDiff(array $expected, array $actual, int $context): string
    {
        $now = date('Y-m-d H:i:s');

        $lines = [];
        $lines[] = "--- expected.txt\t{$now}";
        $lines[] = "+++ actual.txt\t{$now}";

        $ops = $this->diffOps($expected, $actual);

        $hasChanges = false;
        foreach ($ops as $op) {
            if ($op['op'] !== 'same') {
                $hasChanges = true;
                break;
            }
        }

        if (!$hasChanges) {
            $lines[] = '@@ -1,' . count($expected) . ' +1,' . count($actual) . ' @@';
            $lines[] = ' (no differences)';
            return implode("\n", $lines) . "\n";
        }

        // Region the changed ops with ±context calm lines, merging regions
        // whose surrounding context overlaps (finding 027: the old layout
        // reprinted context per change and skipped the leading surround).
        $changeIdx = [];
        foreach ($ops as $i => $op) {
            if ($op['op'] !== 'same') {
                $changeIdx[] = $i;
            }
        }

        /** @var list<int> $regionStarts */
        $regionStarts = [];
        /** @var list<int> $regionEnds */
        $regionEnds = [];
        foreach ($changeIdx as $idx) {
            $start = max(0, $idx - $context);
            $end = min(count($ops) - 1, $idx + $context);
            if ($regionStarts !== [] && $start <= $regionEnds[count($regionEnds) - 1] + 1) {
                $regionEnds[count($regionEnds) - 1] = $end;
            } else {
                $regionStarts[] = $start;
                $regionEnds[] = $end;
            }
        }

        // Running a-side / b-side line counts before each op index.
        $aBefore = [];
        $bBefore = [];
        $aPos = 0;
        $bPos = 0;
        foreach ($ops as $i => $op) {
            $aBefore[$i] = $aPos;
            $bBefore[$i] = $bPos;
            if ($op['op'] === 'same') {
                $aPos++;
                $bPos++;
            } elseif ($op['op'] === 'del') {
                $aPos++;
            } else {
                $bPos++;
            }
        }

        foreach ($regionStarts as $r => $start) {
            $body = $this->renderHunkBody($ops, $start, $regionEnds[$r], $aBefore, $bBefore);
            $lines[] = $body['header'];
            foreach ($body['lines'] as $row) {
                $lines[] = $row;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render one hunk (header + rows) over an op-index window.
     *
     * @param list<array{op: string, text: string}> $ops
     * @param array<int, int> $aBefore
     * @param array<int, int> $bBefore
     * @return array{header: string, lines: list<string>}
     */
    private function renderHunkBody(array $ops, int $start, int $end, array $aBefore, array $bBefore): array
    {
        $expLen = 0;
        $actLen = 0;
        $rows = [];
        $pendingMinus = [];
        $pendingPlus = [];

        for ($i = $start; $i <= $end; $i++) {
            $op = $ops[$i];
            $escaped = $this->escapeDiffLine($op['text']);
            if ($op['op'] === 'same') {
                foreach ($pendingMinus as $row) {
                    $rows[] = "-{$row}";
                    $expLen++;
                }
                foreach ($pendingPlus as $row) {
                    $rows[] = "+{$row}";
                    $actLen++;
                }
                $pendingMinus = [];
                $pendingPlus = [];
                $rows[] = " {$escaped}";
                $expLen++;
                $actLen++;
            } elseif ($op['op'] === 'del') {
                $pendingMinus[] = $escaped;
            } else {
                $pendingPlus[] = $escaped;
            }
        }
        foreach ($pendingMinus as $row) {
            $rows[] = "-{$row}";
            $expLen++;
        }
        foreach ($pendingPlus as $row) {
            $rows[] = "+{$row}";
            $actLen++;
        }

        // GNU header convention: a zero-length side starts at the line it
        // sits AFTER (no +1), e.g. a pure append '@@ -15,0 +16,2 @@'.
        $expStart = $expLen > 0 ? $aBefore[$start] + 1 : $aBefore[$start];
        $actStart = $actLen > 0 ? $bBefore[$start] + 1 : $bBefore[$start];

        return [
            'header' => "@@ -{$expStart},{$expLen} +{$actStart},{$actLen} @@",
            'lines' => $rows,
        ];
    }

    /**
     * Shortest line-level edit script (Myers greedy snake walk) expressed
     * as an op list. Shared prefix and suffix are trimmed up front so a
     * one-line change in a 2000-line screen costs O(1) walk, and the walk
     * itself is bounded by {@see MAX_DIFF_EDITS}.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op: 'same'|'del'|'ins', text: string}>
     */
    private function diffOps(array $a, array $b): array
    {
        $ops = [];

        $n = count($a);
        $m = count($b);

        // Shared prefix.
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $ops[] = ['op' => 'same', 'text' => $a[$prefix]];
            $prefix++;
        }
        // Shared suffix (kept aside, appended after the middle walk).
        $suffix = 0;
        while (
            $suffix < $n - $prefix && $suffix < $m - $prefix
            && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]
        ) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $m - $prefix - $suffix);
        foreach ($this->myersMid($midA, $midB) as $op) {
            $ops[] = $op;
        }
        for ($i = $suffix - 1; $i >= 0; $i--) {
            $ops[] = ['op' => 'same', 'text' => $a[$n - 1 - $i]];
        }

        return $ops;
    }

    /**
     * The core Myers greedy walk plus trace backwalk over the (already
     * prefix/suffix-trimmed) middles.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op: 'same'|'del'|'ins', text: string}>
     */
    private function myersMid(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return array_map(
                static fn (string $line): array => ['op' => 'ins', 'text' => $line],
                $b,
            );
        }
        if ($m === 0) {
            return array_map(
                static fn (string $line): array => ['op' => 'del', 'text' => $line],
                $a,
            );
        }

        /** @var array<int, int> $v */
        $v = [1 => 0];
        /** @var list<array<int, int>> $trace */
        $trace = [];
        $max = $n + $m;
        $foundAt = null;

        for ($d = 0; $d <= $max && $d <= self::MAX_DIFF_EDITS; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? PHP_INT_MIN) < ($v[$k + 1] ?? PHP_INT_MIN))) {
                    $x = $v[$k + 1] ?? 0; // move down (insertion)
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1; // move right (deletion)
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $foundAt = $d;
                    break 2;
                }
            }
        }

        if ($foundAt === null) {
            throw new \RuntimeException(sprintf(
                'diff exceeded the %d-edit complexity guard - refusing to build a diff nobody can read',
                self::MAX_DIFF_EDITS,
            ));
        }

        // Backwalk the trace to emit ops oldest-first.
        /** @var list<array{op: 'same'|'del'|'ins', text: string}> $ops */
        $ops = [];
        $x = $n;
        $y = $m;
        for ($d = $foundAt; $d >= 0; $d--) {
            $snapshot = $trace[$d];
            $k = $x - $y;
            if ($d === 0) {
                // Round 0 is the seed diagonal: the whole path is one snake
                // back to the origin — the generic prev_k = k-1 lookup would
                // read the never-set V[-1] slot and strand its last line.
                $prevX = 0;
                $prevY = 0;
            } else {
                if ($k === -$d || ($k !== $d && ($snapshot[$k - 1] ?? PHP_INT_MIN) < ($snapshot[$k + 1] ?? PHP_INT_MIN))) {
                    $prevK = $k + 1; // came DOWN (insertion)
                } else {
                    $prevK = $k - 1; // came RIGHT (deletion)
                }
                $prevX = $snapshot[$prevK] ?? 0;
                $prevY = $prevX - $prevK;
            }
            while ($x > $prevX && $y > $prevY) {
                $ops[] = ['op' => 'same', 'text' => $a[$x - 1]];
                $x--;
                $y--;
            }
            if ($d > 0) {
                if ($prevK === $k - 1) {
                    $ops[] = ['op' => 'del', 'text' => $a[$x - 1]];
                    $x--;
                } else {
                    $ops[] = ['op' => 'ins', 'text' => $b[$y - 1]];
                    $y--;
                }
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }

    /**
     * Escape a line for diff output.
     *
     * Covers every C0 control (with \e for ESC and \a for BEL), DEL and
     * the C1 block: recorded terminal bytes are DATA, never live chrome.
     * The old list omitted \x1b and \x07, so an SGR sequence copied
     * straight out of a cassette fired for real in the pager and in the
     * written .diff (finding 106).
     *
     * UTF-8 aware: a byte in 0x80-0x9F is a stray C1 control only when it
     * does not continue a valid multi-byte sequence — recorded CJK output
     * keeps half its continuation bytes in that band, and a blind byte
     * class would mangle the very payload the report exists to show. An
     * ENCODED C1 codepoint (U+0080-U+009F as C2 80-C2 9F) is still a
     * control and escapes as one named byte; anything that is neither
     * printable ASCII nor a well-formed sequence degrades to per-byte
     * \xNN for the control range, raw for the rest (the pre-existing
     * high-byte posture).
     */
    private function escapeDiffLine(string $line): string
    {
        $line = str_replace('\\', '\\\\', $line);

        // Left-to-right alternation: valid printable sequences pass, the
        // encoded-C1 pair escapes as one codepoint, every surviving single
        // byte falls to the per-byte control rule below.
        $pattern = '/(?:[\x20-\x7E]+'
            . '|\xC2[\xA0-\xBF]'
            . '|[\xC3-\xDF][\x80-\xBF]'
            . '|[\xE0-\xEF][\x80-\xBF]{2}'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            . '|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2})+'
            . '|\xC2[\x80-\x9F]'
            . '|./s';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m): string {
                $seen = $m[0];
                $length = strlen($seen);
                if ($length > 2 || ($length === 2 && !(ord($seen[0]) === 0xC2 && ord($seen[1]) <= 0x9F))) {
                    return $seen; // well-formed printable run
                }
                if ($length === 2) {
                    return sprintf('\\x%02X', ord($seen[1])); // encoded C1 codepoint
                }
                $code = ord($seen);

                return match (true) {
                    $code === 0x00 => '\\0',
                    $code === 0x07 => '\\a',
                    $code === 0x08 => '\\b',
                    $code === 0x09 => '\\t',
                    $code === 0x0A => '\\n',
                    $code === 0x0B => '\\v',
                    $code === 0x0C => '\\f',
                    $code === 0x0D => '\\r',
                    $code === 0x1B => '\\e',
                    $code < 0x20 || $code === 0x7F || $code <= 0x9F
                        => sprintf('\\x%02X', $code),
                    default => $seen, // stray high byte: raw (harmless data)
                };
            },
            $line,
        );
    }
}

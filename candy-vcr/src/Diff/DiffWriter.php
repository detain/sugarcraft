<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Diff;

/**
 * Writes annotated diff output in unified diff format to a file.
 *
 * When a replay assertion fails, this class can generate a side-by-side
 * or unified diff showing the expected vs actual output, with ANSI color
 * coding for terminal display.
 *
 * Example:
 * ```php
 * $writer = new DiffWriter();
 * $writer->writeUnifiedDiff('/tmp/replay.diff', $expected, $actual);
 * ```
 */
final class DiffWriter
{
    /**
     * Write a unified diff to a file.
     *
     * The unified diff format is compatible with `diff -u` and can be
     * used with standard diff tools for manual inspection.
     *
     * @param string $path Output file path
     * @param string $expected Expected output (from cassette)
     * @param string $actual Actual output (from program)
     * @param string $context Number of context lines around changes (default: 3)
     * @return int Number of bytes written, or false on failure
     */
    public function writeUnifiedDiff(
        string $path,
        string $expected,
        string $actual,
        int $context = 3,
    ): int|false {
        $diff = $this->buildUnifiedDiff($expected, $actual, $context);
        $result = file_put_contents($path, $diff);
        return $result !== false ? strlen($diff) : false;
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
        $expectedLines = explode("\n", $this->normalizeLineEndings($expected));
        $actualLines = explode("\n", $this->normalizeLineEndings($actual));

        $diff = $this->generateUnifiedDiffLines($expectedLines, $actualLines, $context);

        return $this->formatUnifiedDiff($diff, $expected, $actual);
    }

    /**
     * Write a colored ANSI diff suitable for terminal output.
     *
     * @param string $expected Expected output (from cassette)
     * @param string $actual Actual output (from program)
     * @param resource $output Output stream (default: STDOUT)
     */
    public function writeAnsiDiff(string $expected, string $actual, $output = STDOUT): void
    {
        $expectedLines = explode("\n", $this->normalizeLineEndings($expected));
        $actualLines = explode("\n", $this->normalizeLineEndings($actual));

        $maxLines = max(count($expectedLines), count($actualLines));
        $maxWidth = 0;
        for ($i = 0; $i < $maxLines; $i++) {
            $expLine = $expectedLines[$i] ?? '';
            $actLine = $actualLines[$i] ?? '';
            $maxWidth = max($maxWidth, strlen($expLine), strlen($actLine));
        }

        $sep = str_repeat('─', $maxWidth + 2);

        fwrite($output, "\n");
        fwrite($output, "\033[1;33m┌{$sep}┐\033[0m\n"); // Yellow border, expected header
        fwrite($output, "\033[1;33m│ EXPECTED". str_repeat(' ', $maxWidth - 8) . "│\033[0m\n");
        fwrite($output, "\033[1;33m└{$sep}┘\033[0m\n");

        for ($i = 0; $i < $maxLines; $i++) {
            $expLine = $expectedLines[$i] ?? '';
            $actLine = $actualLines[$i] ?? '';
            $isSame = $expLine === $actLine;
            $color = $isSame ? "\033[32m" : "\033[31m"; // Green for same, Red for diff
            $prefix = $isSame ? ' ' : '!';
            fwrite($output, sprintf(
                "%s│ %-{$maxWidth}s │\033[0m\n",
                $color,
                $expLine,
            ));
        }

        fwrite($output, "\n");
        fwrite($output, "\033[1;36m┌{$sep}┐\033[0m\n"); // Cyan border, actual header
        fwrite($output, "\033[1;36m│ ACTUAL  ". str_repeat(' ', $maxWidth - 8) . "│\033[0m\n");
        fwrite($output, "\033[1;36m└{$sep}┘\033[0m\n");

        for ($i = 0; $i < $maxLines; $i++) {
            $expLine = $expectedLines[$i] ?? '';
            $actLine = $actualLines[$i] ?? '';
            $isSame = $expLine === $actLine;
            $color = $isSame ? "\033[32m" : "\033[31m";
            fwrite($output, sprintf(
                "%s│ %-{$maxWidth}s │\033[0m\n",
                $color,
                $actLine,
            ));
        }
        fwrite($output, "\n");
    }

    /**
     * Normalize line endings to LF for consistent diff output.
     */
    private function normalizeLineEndings(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Generate the raw diff lines for a unified diff.
     *
     * @return array{haves: list<string>, lacks: list<string>, changes: list<array{type: string, line: int, exp: string, act: string}>}
     */
    private function generateUnifiedDiffLines(array $expected, array $actual, int $context): array
    {
        $changes = [];
        $maxLines = max(count($expected), count($actual));

        for ($i = 0; $i < $maxLines; $i++) {
            $expLine = $expected[$i] ?? '';
            $actLine = $actual[$i] ?? '';
            if ($expLine !== $actLine) {
                $changes[] = [
                    'type' => 'change',
                    'line' => $i + 1,
                    'exp' => $expLine,
                    'act' => $actLine,
                ];
            }
        }

        return [
            'haves' => $expected,
            'lacks' => $actual,
            'changes' => $changes,
        ];
    }

    /**
     * Format the diff lines into a unified diff string.
     *
     * @param array{haves: list<string>, lacks: list<string>, changes: list<array{type: string, line: int, exp: string, act: string}>} $diff
     */
    private function formatUnifiedDiff(array $diff, string $expected, string $actual): string
    {
        $expectedFile = 'expected.txt';
        $actualFile = 'actual.txt';
        $now = date('Y-m-d H:i:s');

        $lines = [];
        $lines[] = "--- {$expectedFile}\t{$now}";
        $lines[] = "+++ {$actualFile}\t{$now}";

        if ($diff['changes'] === []) {
            $lines[] = '@@ -1,' . count($diff['haves']) . ' +1,' . count($diff['lacks']) . ' @@';
            $lines[] = ' (no differences)';
            return implode("\n", $lines) . "\n";
        }

        // Group changes by proximity and emit hunks
        $changes = $diff['changes'];
        $hunks = $this->emitHunks($changes, $diff['haves'], $diff['lacks'], 3);

        return implode("\n", $lines) . "\n" . implode("\n", $hunks) . "\n";
    }

    /**
     * Emit unified diff hunks from a list of changes.
     *
     * @param list<array{type: string, line: int, exp: string, act: string}> $changes
     * @param list<string> $haves
     * @param list<string> $lacks
     * @param int $context
     * @return list<string>
     */
    private function emitHunks(array $changes, array $haves, array $lacks, int $context): array
    {
        if ($changes === []) {
            return [];
        }

        $hunkLines = [];
        $hunkStart = null;
        $hunkContents = [];

        foreach ($changes as $i => $change) {
            $lineNo = $change['line'];

            if ($hunkStart === null) {
                $hunkStart = max(1, $lineNo - $context);
                $hunkContents = [];
            }

            // Check if this change is close enough to the current hunk
            $lastLine = end($hunkContents);
            if ($lastLine !== false && $lineNo - $lastLine['line'] <= $context + 1) {
                // Add context before this change
                $this->addContext($hunkContents, $haves, $lineNo, $context, 'exp');
            } elseif ($hunkContents !== []) {
                // Emit current hunk and start a new one
                $hunkLines[] = $this->formatHunk($hunkStart, $hunkContents, count($haves), count($lacks));
                $hunkStart = max(1, $lineNo - $context);
                $hunkContents = [];
            }

            // Add the change lines
            $hunkContents[] = ['type' => '-', 'line' => $lineNo, 'content' => $change['exp']];
            $hunkContents[] = ['type' => '+', 'line' => $lineNo, 'content' => $change['act']];

            // Add trailing context
            $this->addContext($hunkContents, $haves, $lineNo + 1, $context, 'exp');
        }

        // Emit the last hunk
        if ($hunkContents !== []) {
            $hunkLines[] = $this->formatHunk($hunkStart, $hunkContents, count($haves), count($lacks));
        }

        return $hunkLines;
    }

    /**
     * Add context lines around a change.
     *
     * @param array &$hunkContents
     * @param list<string> $lines
     * @param int $startLine
     * @param int $context
     * @param string $which 'exp' or 'act'
     */
    private function addContext(array &$hunkContents, array $lines, int $startLine, int $context, string $which): void
    {
        $endLine = min(count($lines), $startLine + $context);
        for ($j = $startLine; $j < $endLine; $j++) {
            if ($j > 0 && $j <= count($lines)) {
                $line = $lines[$j - 1] ?? '';
                $hunkContents[] = ['type' => ' ', 'line' => $j, 'content' => $line];
            }
        }
    }

    /**
     * Format a single hunk in unified diff format.
     */
    private function formatHunk(int $start, array $contents, int $expCount, int $actCount): string
    {
        // Find the ranges for the hunk
        $expStart = null;
        $expCount = 0;
        $actStart = null;
        $actCount = 0;

        foreach ($contents as $c) {
            if ($c['type'] === '-') {
                if ($expStart === null) {
                    $expStart = $c['line'];
                }
                $expCount++;
            } elseif ($c['type'] === '+') {
                if ($actStart === null) {
                    $actStart = $c['line'];
                }
                $actCount++;
            } else {
                // context line counts to both
                if ($expStart === null) {
                    $expStart = $c['line'];
                }
                if ($actStart === null) {
                    $actStart = $c['line'];
                }
            }
        }

        $expStart = $expStart ?? 1;
        $actStart = $actStart ?? 1;

        $hunkHeader = "@@ -{$expStart},{$expCount} +{$actStart},{$actCount} @@";

        $hunkLines = [$hunkHeader];
        foreach ($contents as $c) {
            $prefix = $c['type'];
            $escaped = $this->escapeDiffLine($c['content']);
            $hunkLines[] = "{$prefix}{$escaped}";
        }

        return implode("\n", $hunkLines);
    }

    /**
     * Escape a line for diff output.
     */
    private function escapeDiffLine(string $line): string
    {
        // Handle special characters for unified diff
        $line = str_replace(
            ["\\", "\0", "\n", "\r", "\t", "\f", "\v"],
            ['\\\\', '\\0', "\\n", '\\r', '\\t', '\\f', '\\v'],
            $line,
        );
        return $line;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Lint;

/**
 * What one lint of one file found, and the model-visible text it becomes
 * (step 3.E).
 *
 * THE TEXT IS AIDER'S, because Aider is where the post-edit lint loop comes
 * from and its format is the one models have seen most: the command that ran,
 * its output, then the file's flagged lines marked with `█` among `│`-marked
 * context, with `⋮...` where lines were skipped —
 *
 *     # Fix any errors below, if possible.
 *
 *     ## Running: php -l src/Foo.php
 *
 *     PHP Parse error:  Unmatched '}' in src/Foo.php on line 4
 *
 *     ## See relevant line below marked with █.
 *
 *     src/Foo.php:
 *       1│<?php
 *       2│$x = 1;
 *       3│$y = 2
 *       4█}
 *
 * A clean lint renders as '' — nothing reaches the model, so a chain whose
 * only note would have been "no errors" leaves the tool result byte-identical.
 *
 * Built by {@see LintRunner::lint()}; immutable.
 */
final readonly class LintReport
{
    /** Lines of context kept on each side of a flagged line (Aider's `loi_pad`). */
    public const CONTEXT_LINES = 3;

    /** At most this many flagged lines are marked; the rest are in the output above. */
    public const MAX_MARKED_LINES = 10;

    /**
     * Bytes of the linter's own output kept in the rendered text. The output
     * goes FIRST, so a linter that prints a page per error would otherwise
     * push the marked lines — the part that locates the problem — past the
     * hook's note cap.
     */
    public const MAX_OUTPUT_BYTES = 4096;

    /** Longest source line shown in the excerpt, in characters. */
    public const MAX_LINE_CHARS = 200;

    /** Exit codes the shell gives a command it could not run (126 not executable, 127 not found). */
    private const COULD_NOT_RUN = [126, 127];

    /**
     * @param string $file the path the linter was given — relative to the
     *        project root when the file is inside it, absolute otherwise
     * @param string $command the full command line that ran
     * @param string $output stderr, then stdout, trimmed
     * @param string $source the file's contents when it was linted, for the
     *        excerpt
     */
    public function __construct(
        public string $file,
        public string $command,
        public int $exitCode,
        public string $output,
        public bool $timedOut,
        public float $timeoutSeconds,
        public string $source,
    ) {}

    /** True when the linter ran to completion and exited 0. */
    public function passed(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }

    /** True when the shell could not run the lint command at all. */
    public function couldNotRun(): bool
    {
        return !$this->timedOut && in_array($this->exitCode, self::COULD_NOT_RUN, true);
    }

    /**
     * The 1-based lines of {@see $file} the output points at, ascending, each
     * once, at most {@see MAX_MARKED_LINES}.
     *
     * Two spellings are read: `<file>:<line>` (Aider's
     * `find_filenames_and_linenums`, and what most linters print) and
     * `… on line <n>` (PHP's own). A line past the end of the file is
     * dropped rather than marked, since there is nothing there to show.
     *
     * @return list<int>
     */
    public function lines(): array
    {
        $names = array_unique(array_filter([$this->file, basename($this->file)]));
        $found = [];

        foreach ($names as $name) {
            if (preg_match_all('/(?:^|[\s"\'(\/])' . preg_quote($name, '/') . ':(\d+)/m', $this->output, $m) > 0) {
                $found = [...$found, ...$m[1]];
            }
        }

        if (preg_match_all('/ on line (\d+)/', $this->output, $m) > 0) {
            $found = [...$found, ...$m[1]];
        }

        $count = count($this->sourceLines());
        $lines = array_values(array_unique(array_filter(
            array_map('intval', $found),
            static fn (int $n): bool => $n >= 1 && $n <= $count,
        )));
        sort($lines);

        return array_slice($lines, 0, self::MAX_MARKED_LINES);
    }

    /**
     * The model-visible text — see the class docblock. '' for a clean lint.
     */
    public function render(): string
    {
        if ($this->passed()) {
            return '';
        }

        $head = '## Running: ' . $this->command . "\n\n";

        if ($this->timedOut) {
            return $head . sprintf(
                'The lint did not finish within %s seconds and was stopped, so %s was not checked.',
                rtrim(rtrim(number_format($this->timeoutSeconds, 3, '.', ''), '0'), '.'),
                $this->file,
            );
        }

        if ($this->couldNotRun()) {
            return $head . sprintf(
                'The lint command could not run (exit %d), so %s was not checked. %s',
                $this->exitCode,
                $this->file,
                self::clip($this->output),
            );
        }

        $text = "# Fix any errors below, if possible.\n\n" . $head
            . ($this->output === '' ? sprintf('(the linter exited %d and printed nothing)', $this->exitCode) : self::clip($this->output))
            . "\n";

        $lines = $this->lines();
        if ($lines === []) {
            return $text;
        }

        return $text . sprintf("\n## See relevant line%s below marked with █.\n\n", count($lines) > 1 ? 's' : '')
            . $this->file . ":\n"
            . $this->excerpt($lines);
    }

    /**
     * The flagged lines with {@see CONTEXT_LINES} of context each, merged
     * where they overlap, `⋮...` between the runs.
     *
     * @param list<int> $marked
     */
    private function excerpt(array $marked): string
    {
        $source = $this->sourceLines();
        $last = count($source);

        $shown = [];
        foreach ($marked as $line) {
            for ($n = max(1, $line - self::CONTEXT_LINES); $n <= min($last, $line + self::CONTEXT_LINES); $n++) {
                $shown[$n] = true;
            }
        }
        ksort($shown);

        $width = strlen((string) max(array_keys($shown)));
        $flagged = array_flip($marked);
        $out = '';
        $previous = 0;

        foreach (array_keys($shown) as $n) {
            if ($n > $previous + 1) {
                $out .= "⋮...\n";
            }
            $out .= str_pad((string) $n, $width, ' ', STR_PAD_LEFT)
                . (isset($flagged[$n]) ? '█' : '│')
                . self::visible($source[$n - 1]) . "\n";
            $previous = $n;
        }

        if ($previous < $last) {
            $out .= "⋮...\n";
        }

        return $out;
    }

    /**
     * The source split into lines, a final newline ending the last line
     * rather than opening an empty one.
     *
     * @return list<string>
     */
    private function sourceLines(): array
    {
        if ($this->source === '') {
            return [];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $this->source);

        return explode("\n", str_ends_with($text, "\n") ? substr($text, 0, -1) : $text);
    }

    /**
     * One source line as safe model text: valid UTF-8, no control bytes but
     * tab, at most {@see MAX_LINE_CHARS}.
     */
    private static function visible(string $line): string
    {
        $line = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', mb_scrub($line, 'UTF-8'));

        return mb_strlen($line) <= self::MAX_LINE_CHARS
            ? $line
            : mb_substr($line, 0, self::MAX_LINE_CHARS) . '…';
    }

    /** The linter's output, head kept, cut at {@see MAX_OUTPUT_BYTES} with a marker. */
    private static function clip(string $output): string
    {
        $output = mb_scrub($output, 'UTF-8');
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return mb_strcut($output, 0, self::MAX_OUTPUT_BYTES, 'UTF-8')
            . sprintf("\n… [lint output truncated: %d of %d bytes shown]", self::MAX_OUTPUT_BYTES, strlen($output));
    }
}

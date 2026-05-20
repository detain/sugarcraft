<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy;

/**
 * Bulk rename engine with regex template + sequential numbering.
 *
 * Mirrors charmbracelet/superfile.bulkRename logic.
 * Immutable + fluent — every with*() returns a new instance.
 */
final class BulkRename
{
    /**
     * @param list<string>          $files      Ordered list of source file names (no path)
     * @param string                $pattern     PCRE regex pattern to match against each file name
     * @param string                $template   Replacement template with placeholders like $1, {n}, {name}
     * @param int                   $startNum   Starting number for {n} placeholder
     * @param int                   $stepNum     Increment step for each successive file
     * @param int                   $padNum     Zero-padding width for {n} (0 = no padding)
     * @param string                $error      Last error message
     */
    public function __construct(
        public readonly array $files,
        public readonly string $pattern = '',
        public readonly string $template = '{name}',
        public readonly int $startNum = 1,
        public readonly int $stepNum = 1,
        public readonly int $padNum = 1,
        public readonly string $error = '',
    ) {}

    /**
     * Build a new instance with a list of files to rename.
     *
     * @param list<string> $files
     */
    public static function files(array $files): self
    {
        return new self($files);
    }

    /**
     * Set the PCRE regex pattern.
     */
    public function withPattern(string $pattern): self
    {
        return $this->mutate(pattern: $pattern);
    }

    /**
     * Set the replacement template.
     *
     * Supported placeholders:
     * - {name}  — original file name without extension
     * - {ext}   — file extension (without leading dot)
     * - {n}     — sequential number (respects startNum/stepNum/padNum)
     * - {N}     — sequential number without padding
     * - $1..$9  — captured subgroups from the pattern
     */
    public function withTemplate(string $template): self
    {
        return $this->mutate(template: $template);
    }

    /**
     * Set the starting number for sequential numbering.
     */
    public function withStartNum(int $startNum): self
    {
        return $this->mutate(startNum: $startNum);
    }

    /**
     * Set the step for sequential numbering.
     */
    public function withStepNum(int $stepNum): self
    {
        return $this->mutate(stepNum: $stepNum);
    }

    /**
     * Set the zero-padding width for {n}.
     */
    public function withPadNum(int $padNum): self
    {
        return $this->mutate(padNum: $padNum);
    }

    /**
     * True when a pattern is set and appears valid.
     */
    public function hasValidPattern(): bool
    {
        if ($this->pattern === '') {
            return false;
        }
        set_error_handler(static fn() => null);
        try {
            return @preg_match($this->pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Generate preview of renamed files.
     *
     * @return list<array{original: string, renamed: string}>
     */
    public function preview(): array
    {
        if (!$this->hasValidPattern() || $this->files === []) {
            return [];
        }

        $results = [];
        $num = $this->startNum;

        foreach ($this->files as $file) {
            $renamed = $this->applyTemplate($file, $num);
            $results[] = ['original' => $file, 'renamed' => $renamed];
            $num += $this->stepNum;
        }

        return $results;
    }

    /**
     * Check if a specific file name would be changed by the current settings.
     */
    public function willChange(string $file): bool
    {
        if (!$this->hasValidPattern()) {
            return false;
        }
        $result = $this->applyTemplateToOne($file, $this->startNum);
        return $result !== '' && $result !== $file;
    }

    /**
     * Apply template to a single file name at a given number.
     */
    public function applyTemplateToOne(string $file, int $number): string
    {
        if (!$this->hasValidPattern()) {
            return $file;
        }

        $replaced = @preg_replace($this->pattern, $this->template, $file);
        if ($replaced === null) {
            return $file;
        }

        $info = pathinfo($file);
        $name = $info['filename'] ?? '';
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $extNoDot = $info['extension'] ?? '';

        $withName = str_replace('{name}', $name, $replaced);
        $withExt = str_replace('{ext}', $extNoDot, $withName);

        $padded = str_pad((string) $number, $this->padNum, '0', STR_PAD_LEFT);
        $withN = str_replace('{n}', $padded, $withExt);
        $withN = str_replace('{N}', (string) $number, $withN);

        // Restore extension if we replaced it into the middle of the name
        if ($ext !== '' && !str_ends_with($withN, $ext)) {
            $withN .= $ext;
        }

        return $withN;
    }

    /**
     * Apply the current template to generate all rename targets.
     *
     * @return list<string>  List of new names (not full paths)
     */
    public function renamed(): array
    {
        if (!$this->hasValidPattern() || $this->files === []) {
            return [];
        }

        $results = [];
        $num = $this->startNum;

        foreach ($this->files as $file) {
            $results[] = $this->applyTemplate($file, $num);
            $num += $this->stepNum;
        }

        return $results;
    }

    /**
     * Last error message from template application.
     */
    public function lastError(): string
    {
        return $this->error;
    }

    /**
     * True when the current configuration would rename files without conflicts.
     */
    public function isValid(): bool
    {
        if (!$this->hasValidPattern() || $this->files === []) {
            return false;
        }

        $renamed = $this->renamed();
        $unique = array_unique($renamed);

        // Check for empty names or duplicates
        foreach ($renamed as $name) {
            if ($name === '' || $name === '.') {
                return false;
            }
        }

        return count($unique) === count($renamed);
    }

    /**
     * Run the rename operation and return error count.
     *
     * @param \Closure(string $src, string $dst): bool $renamer  Actual rename function
     * @param string                                             $cwd        Directory containing the files
     * @return int  Number of failed renames
     */
    public function execute(\Closure $renamer, string $cwd): int
    {
        $preview = $this->preview();
        $errors = 0;

        foreach ($preview as $item) {
            $src = Pane::join($cwd, $item['original']);
            $dst = Pane::join($cwd, $item['renamed']);

            if ($item['original'] === $item['renamed']) {
                continue;
            }

            if (!$renamer($src, $dst)) {
                $errors++;
            }
        }

        return $errors;
    }

    /**
     * @param string $file
     * @param int    $number
     * @return string
     */
    private function applyTemplate(string $file, int $number): string
    {
        return $this->applyTemplateToOne($file, $number);
    }

    /**
     * Generic mutable builder — creates a new instance with one changed field.
     *
     * @param string|null $pattern
     * @param string|null $template
     * @param int|null    $startNum
     * @param int|null    $stepNum
     * @param int|null    $padNum
     * @param string|null $error
     */
    private function mutate(
        ?string $pattern = null,
        ?string $template = null,
        ?int $startNum = null,
        ?int $stepNum = null,
        ?int $padNum = null,
        ?string $error = null,
    ): self {
        return new self(
            $this->files,
            $pattern ?? $this->pattern,
            $template ?? $this->template,
            $startNum ?? $this->startNum,
            $stepNum ?? $this->stepNum,
            $padNum ?? $this->padNum,
            $error ?? $this->error,
        );
    }
}

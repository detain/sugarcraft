<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Ignore;

/**
 * Loads .sugartrackignore glob patterns and matches file paths.
 *
 * Patterns:
 *   *.log       — ignore all log files
 *   vendor/*   — ignore vendor directory
 *   /full/path — ignore specific path
 */
final class SugarTrackIgnore
{
    /** @param array<string> $patterns glob patterns */
    public function __construct(private readonly array $patterns = [])
    {}

    /**
     * Load patterns from an ignore file.
     *
     * Lines starting with # are treated as comments and ignored.
     * Empty lines are also skipped.
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return new self([]);
        }

        $lines = array_map('trim', $lines);
        $patterns = array_filter(
            $lines,
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        );

        return new self(array_values($patterns));
    }

    /**
     * Returns true if the file path should be ignored.
     */
    public function isIgnored(string $path): bool
    {
        foreach ($this->patterns as $pat) {
            if (fnmatch($pat, basename($path))) {
                return true;
            }
            if (fnmatch($pat, $path)) {
                return true;
            }
        }
        return false;
    }
}

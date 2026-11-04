<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Ignore;

/**
 * Loads .sugartrackignore glob patterns and matches file paths.
 *
 * A pattern matches if it hits the path's BASENAME ("*.log" matches any log
 * file, anywhere), the FULL path ("/home/u/secrets/x.php" matches exactly), or
 * any trailing SUBPATH from a directory boundary onward — so the documented
 * "vendor/*" example matches "/home/u/proj/vendor/lib.php" as naturally as it
 * matches "vendor/lib.php". Before that third leg existed, "vendor/*" matched
 * neither an absolute path nor a bare basename, and the privacy opt-out was a
 * silent no-op for exactly the editor-plugin pushes it was advertised for.
 *
 * Patterns:
 *   *.log      — ignore all log files
 *   vendor/*   — ignore vendor directory (at any depth)
 *   /full/path — ignore specific path
 *
 * The lookup uses the FIRST ignore file found (push checks cwd, then the
 * tracked file's directory), and a file larger than MAX_IGNORE_BYTES is
 * refused loudly rather than slurped: it lives inside a cloned repository,
 * so a multi-gigabyte planted ".sugartrackignore" is an attacker-controlled
 * memory-DoS on every push of an opt-out feature.
 */
final class SugarTrackIgnore
{
    /** Hard ceiling on ignore-file size (1 MiB of lines is generous). */
    public const MAX_IGNORE_BYTES = 1_048_576;

    /** @param array<string> $patterns glob patterns */
    public function __construct(private readonly array $patterns = [])
    {}

    /**
     * Load patterns from an ignore file.
     *
     * Lines starting with # are treated as comments and ignored.
     * Empty lines are also skipped.
     *
     * @throws \RuntimeException if the file exceeds self::MAX_IGNORE_BYTES
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_IGNORE_BYTES) {
            throw new \RuntimeException(
                'candy-codetime: ignore file ' . $path . ' is ' . $size
                . ' bytes, over the ' . self::MAX_IGNORE_BYTES
                . ' byte limit — refusing to read it',
            );
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

    /** Number of active patterns (comments and blank lines excluded). */
    public function patternCount(): int
    {
        return count($this->patterns);
    }

    /**
     * True when some pattern is wildcard-only — it ignores EVERY path the
     * matcher is ever consulted about.
     *
     * Why the empty-string canary is exact: a POSIX-glob pattern matches ""
     * only if every token in it can consume nothing, i.e. the pattern is
     * made solely of star runs ("*", "**"). Such a pattern also matches
     * every non-empty string, so it zeroes every push of the project it was
     * planted in. Honest patterns fail the canary by construction: "*.log"
     * demands a suffix, "?*" demands one character.
     *
     * The file lives inside tracked repositories, so this is the shape of
     * "repo content as untrusted input to the tracking tool": a checked-in
     * ".sugartrackignore" containing a bare "*" silently erases the whole
     * project's recorded time. The CLI routes that skip to STDERR with the
     * file path, pattern count and project so the notice is hard to miss.
     *
     * Disclosed gap: a crafted "?*" ignores every real (non-empty) file name
     * yet fails the empty canary, so it stays on the honest channel. The
     * realistic plant is the bare "*"; over-flagging honest patterns that
     * merely LOOK broad would be the worse trade.
     */
    public function hasWholesalePattern(): bool
    {
        foreach ($this->patterns as $pat) {
            if (fnmatch($pat, '')) {
                return true;
            }
        }

        return false;
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
            // Directory-anchored patterns ("vendor/*", "src/legacy/*") speak
            // relative to the project root, but pushes carry the path the
            // editor reported — often absolute. Test every suffix that starts
            // at a directory boundary so the pattern meets its own contract.
            $offset = 0;
            while (($sep = strpos($path, '/', $offset)) !== false) {
                if (fnmatch($pat, substr($path, $sep + 1))) {
                    return true;
                }
                $offset = $sep + 1;
            }
        }
        return false;
    }
}

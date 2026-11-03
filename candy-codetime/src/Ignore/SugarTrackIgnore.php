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

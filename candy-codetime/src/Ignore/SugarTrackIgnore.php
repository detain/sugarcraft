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
 * memory-DoS on every push of an opt-out feature. An ignore file that exists
 * but cannot be read is refused the same way (fail-closed): tracking with
 * the opt-out in an unknown state is the failure mode the file exists to
 * prevent, so the load throws and the CLI reports it rather than proceeding
 * as if no patterns were configured.
 */
final class SugarTrackIgnore
{
    /** Hard ceiling on ignore-file size (1 MiB of lines is generous). */
    public const MAX_IGNORE_BYTES = 1_048_576;

    /**
     * Behavioural probes for the wholesale canary (see hasWholesalePattern).
     *
     * Deliberately diverse: a one-character name (kills minimum-length
     * tokens), two letters, distinct first characters across the lower,
     * upper and digit families (kills character-class anchors), a path with
     * a separator and a path with a dot (kill suffix/extension claims that
     * never had to see one). Only patterns free of any such anchor match
     * all six — and those are exactly the ones that erase a project.
     */
    private const WHOLESALE_PROBES = ['a', 'zq', '9x', 'Zf', 'a/b', 'a.b'];

    /** @param array<string> $patterns glob patterns */
    public function __construct(private readonly array $patterns = [])
    {}

    /**
     * Load patterns from an ignore file.
     *
     * Lines starting with # are treated as comments and ignored.
     * Empty lines are also skipped.
     *
     * @throws \RuntimeException if the file exceeds self::MAX_IGNORE_BYTES,
     *         or exists but cannot be read — the opt-out is never assumed
     *         empty, because a silently unreadable ignore file would track
     *         exactly the files its author asked to protect.
     */
    public static function load(string $path, string $label = 'ignore file'): self
    {
        if (!file_exists($path)) {
            return new self([]);
        }

        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_IGNORE_BYTES) {
            throw new IgnoreFileTooLargeException($path, $size, self::MAX_IGNORE_BYTES, $label);
        }

        // is_file() would be the blunt test, but on Linux file() against a
        // DIRECTORY succeeds and yields an empty array — a plant standing
        // where the opt-out file should be must not fail open on that
        // accident, so a directory is routed through the same refusal.
        $lines = is_dir($path) ? false : @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException(
                $label . ' ' . $path . ' could not be read — refusing to track with it in an unknown state',
            );
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
     * The empty canary alone under-covers the threat model: a crafted "?*"
     * ignores every real (non-empty) file name yet passes as honest, so a
     * planted file silences the whole project through the quiet channel.
     * The canary is therefore doubled with the behavioural probe set below:
     * a pattern that swallows every probe has no name, prefix, suffix,
     * extension or separator anchor left, and it is wholesale in effect even
     * when it is not wildcard-only in shape. Over-flagging a pathological
     * class that happens to cover all the probes is the accepted trade —
     * the loud lane still records, it only records noisily.
     *
     * The file lives inside tracked repositories, so this is the shape of
     * "repo content as untrusted input to the tracking tool": a checked-in
     * ".sugartrackignore" containing a bare "*" silently erases the whole
     * project's recorded time. The CLI routes that skip to STDERR with the
     * file path, pattern count and project so the notice is hard to miss.
     */
    public function hasWholesalePattern(): bool
    {
        foreach ($this->patterns as $pat) {
            if (fnmatch($pat, '')) {
                return true;
            }

            $swallowsEveryProbe = true;
            foreach (self::WHOLESALE_PROBES as $probe) {
                if (!fnmatch($pat, $probe)) {
                    $swallowsEveryProbe = false;
                    break;
                }
            }
            if ($swallowsEveryProbe) {
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
        return $this->matches($path);
    }

    /**
     * The sibling control of isIgnored, for ".sugartrackallow" files: the
     * same three-leg match, read as a rescue instead of a rejection. The
     * CLI consults an allow-list FIRST — a path matched here is tracked
     * without the deny file ever being consulted — so the two files
     * together give first-match precedence to the allow side (upstream
     * .takatrack parity, finding 045).
     */
    public function isAllowed(string $path): bool
    {
        return $this->matches($path);
    }

    private function matches(string $path): bool
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

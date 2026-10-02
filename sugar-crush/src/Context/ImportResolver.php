<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Recursively expands @-imported .md file references in instruction file content.
 *
 * Resolves "~" paths to the home directory and relative paths against $baseDir.
 * Imports are depth-capped at MAX_DEPTH to prevent infinite recursion.
 * Skips @-references inside fenced and inline code spans (backtick-delimited).
 * Returns the original string unchanged when a referenced file is not found.
 * A referenced file over {@see MAX_IMPORT_BYTES} is not read; its reference is
 * replaced by a {@see deferredImport()} pointer instead.
 *
 * Mirrors opencode's import resolution behaviour.
 */
final class ImportResolver
{
    private const MAX_DEPTH = 4;

    /**
     * The most bytes ONE imported file may be read at (audit 15d-09, C3).
     *
     * An `@import` was read with an unbounded `file_get_contents()` and spliced
     * whole into its parent document, so `@CHANGELOG.md` in a CLAUDE.md put the
     * whole changelog into every request. The figure is
     * {@see InstructionFileLoader::MAX_DOCUMENT_BYTES}, deliberately: an import
     * lands INSIDE a document, so a file the document ceiling would refuse on its
     * own can never be admitted by arriving through an import instead. A file over
     * it is not read at all (its size is a `filesize()` stat) and the reference is
     * replaced by {@see deferredImport()} at its own site.
     *
     * This is the floor every caller gets, gate or no gate. The loader's gate is
     * stricter — it prices each import against what is LEFT of its document's
     * ceiling — so with the loader in front this check only fires on a file that
     * grew between the gate's stat and this read.
     */
    public const MAX_IMPORT_BYTES = InstructionFileLoader::MAX_DOCUMENT_BYTES;

    /**
     * Factory creating an ImportResolver with default settings.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * The inline replacement for an `@import` that is not inlined for budget.
     *
     * Tagged like its two siblings (`import-blocked`, `import-skipped`) so the
     * model can tell a deferred import from text the author wrote, and carrying
     * {@see InstructionFileLoader::pointer()} as its body so a deferred import
     * and a deferred whole document are announced in ONE spelling — the line the
     * model is told to act on cannot drift between the two sites.
     *
     * @param string $pathFragment the reference as the author wrote it
     * @param string $path         the resolved file, which is what the pointer
     *                             names because it is what Read can open
     * @param int    $bytes        the file's size on disk
     */
    public static function deferredImport(string $pathFragment, string $path, int $bytes): string
    {
        return "<import-deferred reason=\"budget\">Import '" . $pathFragment . "' was not inlined. "
            . InstructionFileLoader::pointer($path, $bytes) . '</import-deferred>';
    }

    /**
     * Recursively expand @-imported .md file references in $content.
     *
     * The regex matches @references to .md files that are NOT surrounded
     * by backticks (fenced/inline code spans are left untouched).
     *
     * Path resolution:
     * - Paths starting with "~/" are resolved relative to the home directory.
     * - Paths starting with "./" or "../" are resolved relative to $baseDir.
     * - Bare paths (no prefix) are resolved relative to $baseDir.
     *
     * Depth is capped at MAX_DEPTH (4) to prevent unbounded recursion.
     * When the cap is reached, no further expansion occurs and the
     * unresolved @reference is left as-is.
     *
     * $boundaryCheck (when given) is consulted for EVERY reference this
     * call resolves, at every recursion depth -- not just the references
     * present in the original top-level $content -- so a caller enforcing
     * a root boundary (e.g. "must stay inside the repo") gets that
     * guarantee for chains where an allowed file imports something that
     * itself imports something disallowed. It receives the resolved
     * absolute path, the raw path fragment, and the original `@ref` match
     * text, and returns either `null` (proceed with the normal read +
     * recurse) or a replacement string to substitute in place of following
     * the reference.
     *
     * @param string $content  Raw file content that may contain @-import references.
     * @param string $baseDir  Absolute path used to resolve relative import paths.
     * @param int    $depth    Current recursion depth (starts at 0). Used internally.
     * @param ?callable(string, string, string): ?string $boundaryCheck Optional guard,
     *        threaded through every recursive call; see above.
     * @return string Content with all reachable @-imports expanded.
     */
    public function expand(string $content, string $baseDir, int $depth = 0, ?callable $boundaryCheck = null): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return $content;
        }

        // Split off ```-fenced blocks first so an @reference sitting alone
        // on its own line inside a fence is never touched -- the inline
        // (?<!`)/(?!`) lookaround below only catches backtick-adjacent
        // spans on a single line, not fence state spanning multiple lines.
        $segments = preg_split('/(```.*?```)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            $segments = [$content];
        }

        $result = '';
        foreach ($segments as $i => $segment) {
            // Odd indices are the captured fenced blocks themselves -- leave as-is.
            $result .= ($i % 2 === 1)
                ? $segment
                : $this->expandOutsideFences($segment, $baseDir, $depth, $boundaryCheck);
        }

        return $result;
    }

    /**
     * Expand @references within a segment already known to be outside any
     * fenced code block, still skipping inline single-backtick spans.
     *
     * @param ?callable(string, string, string): ?string $boundaryCheck
     */
    private function expandOutsideFences(string $content, string $baseDir, int $depth, ?callable $boundaryCheck): string
    {
        // Negative lookbehind (?<!`) ensures @ preceded by a backtick is skipped.
        // Negative lookahead (?!`) ensures @ followed by a backtick is skipped.
        $result = preg_replace_callback(
            '/(?<!`)@(\.?\/?[\w\-\.\/~]+\.md)(?!`)/',
            function (array $m) use ($baseDir, $depth, $boundaryCheck): string {
                $pathFragment = $m[1];

                // Resolve ~ (home directory) and relative paths.
                if (str_starts_with($pathFragment, '~/')) {
                    $resolved = getenv('HOME') . substr($pathFragment, 1);
                } else {
                    // Strip only a leading "./" so "../" sequences survive
                    // intact and are resolved by the OS relative to $baseDir.
                    // ltrim($pathFragment, './') would strip EVERY leading
                    // dot/slash character regardless of order, turning
                    // "../parent.md" into "parent.md" and silently looking
                    // in $baseDir itself instead of its parent.
                    $relative = str_starts_with($pathFragment, './')
                        ? substr($pathFragment, 2)
                        : $pathFragment;
                    $resolved = rtrim($baseDir, '/') . '/' . $relative;
                }

                if (!is_file($resolved)) {
                    return $m[0]; // leave unresolved refs untouched
                }

                if ($boundaryCheck !== null) {
                    $real = realpath($resolved) ?: $resolved;
                    $blocked = $boundaryCheck($real, $pathFragment, $m[0]);
                    if ($blocked !== null) {
                        return $blocked;
                    }
                }

                // Measured BEFORE it is read, so a multi-gigabyte import costs a
                // stat rather than the memory to hold it; and read BOUNDED, so a
                // file that grows between the stat and the read still cannot
                // deliver more than the ceiling plus the one byte that proves it
                // overran. Either way the reference becomes a pointer, never a
                // clipped body — half an imported convention reads as the whole.
                $size = filesize($resolved);
                if ($size === false) {
                    return $m[0];
                }

                $shown = realpath($resolved) ?: $resolved;
                if ($size > self::MAX_IMPORT_BYTES) {
                    return self::deferredImport($pathFragment, $shown, $size);
                }

                $imported = file_get_contents($resolved, false, null, 0, self::MAX_IMPORT_BYTES + 1);
                if ($imported === false) {
                    return $m[0];
                }

                if (strlen($imported) > self::MAX_IMPORT_BYTES) {
                    return self::deferredImport($pathFragment, $shown, strlen($imported));
                }

                // An imported file is spliced into an instruction document and
                // so into every request's JSON body; one invalid UTF-8 byte in
                // it used to fail the encode of every turn (audit 15d-08). The
                // note is appended AFTER the recursion so the path it spells is
                // never itself expanded as a reference.
                [$imported, $replaced] = Utf8Scrub::scrub($imported);

                // Recurse with the directory of the imported file as new base.
                // $boundaryCheck is threaded through so a reference found
                // INSIDE this just-imported file is checked too, at every depth.
                return $this->expand($imported, dirname($resolved), $depth + 1, $boundaryCheck)
                    . Utf8Scrub::notice($replaced, $pathFragment);
            },
            $content,
        );

        return $result ?? $content;
    }
}

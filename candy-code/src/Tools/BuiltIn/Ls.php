<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\StreamingDirectoryLister;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\IgnoreRules;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\PathJail;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * List the entries of ONE directory — the tool the model reaches for when the
 * question is "what is in here", not "where does X live" ({@see Glob}) or
 * "which files say X" ({@see Grep}).
 *
 * Built on {@see StreamingDirectoryLister}, the generator that existed in this
 * repo with zero production references. Two things were wrong about reaching
 * for it directly from a tool and one thing was wrong about the lister itself:
 *
 * - The lister skipped EVERY dot-prefixed entry unconditionally. For its
 *   original gum-port purpose that is a feature; for a listing tool it is a
 *   silent omission — `.github`, `.gitignore` and `.env.example` are exactly
 *   the entries a caller asks about when they ask what a directory holds.
 *   Fixed default-byte-compatibly: `list()`/`count()` take an
 *   `$includeHidden` flag whose false default preserves the old walk exactly
 *   (the pinned `testListSkipsDotFiles` and friends hold untouched), and true
 *   drops only the two self references readdir() always reports.
 * - The lister's former `positive-int $chunkSize` constructor knob was
 *   declared and read nowhere — readdir() buffers at the libc layer, so the
 *   value controlled nothing. DELETED rather than wired to a fake batching:
 *   a knob that lies about behavior is worse on a tool's construction path
 *   than on the dormant class it never left. If chunked buffering is ever
 *   measured to matter, it belongs in the generator body, not in a signature.
 * - Jail and exclusion semantics are copied from {@see Glob}, not invented:
 *   {@see PathJail::resolveDir()} settles containment, `ignore_`-class flags
 *   go through {@see IgnoreRules} with the workspace root as the ignore root,
 *   pointing `path` inside an ignored directory opts into it, and every
 *   silent narrowing gets a note after the list. The prune list is the ONE
 *   deliberate divergence: `vendor/`/`node_modules/` pruning exists to spare
 *   RECURSIVE walks the traversal, and a one-level listing neither traverses
 *   nor omits anything by descending into one — the directory's own name IS
 *   the answer, and naming it is how the caller learns the escape hatch works.
 *
 * Output shape: absolute paths, one per line, name-sorted, directories marked
 * with a trailing `/` (stat-based, `ls -p` doctrine — a symlinked directory
 * gets the marker because that is what it behaves like from here), capped at
 * {@see Glob::DEFAULT_MAX_MATCHES} entries so the two tools agree on what
 * "a lot" means. An empty directory is an empty result, not an error.
 */
#[BuiltInTool(name: 'Ls', permission: ToolPermissionClass::Read, position: 36, gloss: 'what is in one directory, name-sorted, directories marked with a trailing "/"')]
final readonly class Ls implements Tool, AcceptsWorktreeJail, ParallelSafe, BuildsFromCatalog
{
    use RebindsWorktreeJail;
    use TruncatesOutput;

    /**
     * Ceiling on how many entries one call returns, and the byte cap still
     * bounds what it says about them. Referenced from {@see Glob} rather than
     * re-literalled: two tools capping the same kind of answer must not drift
     * into answering at different sizes.
     */
    public const DEFAULT_MAX_ENTRIES = Glob::DEFAULT_MAX_MATCHES;

    /**
     * $maxEntries is a test and future-wiring seam, NOT a setting knob: unlike
     * Glob/Grep this tool is deliberately absent from
     * {@see \SugarCraft\Code\Tools\ToolLimits::applyTo()} — one directory per
     * call cannot produce a 8,916-path reply, so the constant needs no
     * user-facing override, and a setting nobody has asked for is a key the
     * settings roster would have to keep explaining forever.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        // Audit F-J5 lineage: LAST, for the positional-argument reason stated
        // in Glob/Grep — cap tests construct these tools positionally.
        private ?AgentPathJail $worktreeJail = null,
    ) {}

    /**
     * Listing a directory reads nothing and writes nothing; the shared
     * {@see IgnoreRules} instance is rebuilt per call, so there is no
     * announce-once mark to carry across a fork either.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root);
    }

    public function name(): string
    {
        return 'Ls';
    }

    /**
     * The lead draws the line between this tool and its two recursive
     * siblings on the first sentence, because "what is in this directory" and
     * "find me files matching X" are questions a model picks between wrong:
     * a Glob for `*` works but answers a different (pruned, pattern-matched)
     * question, and a Bash `ls` escapes the workspace jail's vocabulary.
     */
    public function description(): string
    {
        return 'List the entries of a single directory as absolute paths, one per line, '
            . 'name-sorted, with directories marked by a trailing "/". Reach for this '
            . 'instead of a shell `ls` to see what one directory holds without leaving the '
            . 'jail-checked vocabulary the other file tools take as path arguments — it '
            . 'does not recurse: use Glob to find files by pattern and Grep to find them '
            . 'by content. Dot (hidden) entries are omitted and entries the project\'s '
            . '.gitignore excludes are hidden by default; pass include_hidden: true and '
            . 'include_ignored: true to widen either, and an empty directory is an empty '
            . 'result, not an error.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to list, as an absolute path or one relative to the workspace root. A symlinked directory named as path is listed through its target when the target lies inside the jail; entries that resolve outside are hidden and announced.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise 5-10 word description in active voice of what this listing is for (e.g. "See what the config directory holds", not "ls /tmp").',
                ],
                'include_hidden' => [
                    'type' => 'boolean',
                    'description' => 'Include dot-prefixed entries (.github, .gitignore, .env). Off by default, like `ls` without -a.',
                ],
                'include_ignored' => [
                    'type' => 'boolean',
                    'description' => 'Show entries the project\'s .gitignore excludes (build output, caches, local config). Off by default; the result says when .gitignore hid something.',
                ],
            ],
            'required' => ['path', 'description'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        $path = $args['path'] ?? '';

        if (!is_string($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: $path must be a string',
                isError: true,
            );
        }

        // realpath() THROWS a ValueError on a NUL byte rather than failing —
        // same crash-shape Glob and Grep guard in their own vocabulary.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: path contains a NUL byte',
                isError: true,
            );
        }

        $jailRoot = $this->jailRoot();
        if ($jailRoot !== null) {
            $resolved = PathJail::resolveDir($jailRoot, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        // rtrim eats the filesystem root ("/" -> ""), which is a ValueError
        // source two levels below in opendir — restore it, as Glob does.
        $baseDir = rtrim($path, '/');
        if ($baseDir === '') {
            $baseDir = '/';
        }

        // Containment was settled above; this settles directory-ness.
        // PathJail::resolveDir() hands back existing FILES too (it is a
        // containment predicate), and naming a readable file an unreadable
        // directory sends the model hunting a permission problem that does
        // not exist — the exact Glob/Grep pair of messages, for the exact
        // same reasons.
        if (!is_dir($baseDir)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: sprintf('Error: not a directory: %s', $baseDir),
                isError: true,
            );
        }

        if (!is_readable($baseDir)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: sprintf('Error: directory is not readable: %s', $baseDir),
                isError: true,
            );
        }

        $includeHidden = self::flag($args['include_hidden'] ?? null);
        $rules = IgnoreRules::new($jailRoot ?? $baseDir)
            ->withGitignore(!self::flag($args['include_ignored'] ?? null));

        // Pointing `path` INSIDE an ignored directory is a deliberate request
        // for its contents — Glob's doctrine, verbatim in effect: without this
        // the caller gets a confident "nothing there" about a directory they
        // just named.
        if ($rules->ignores($baseDir, true)) {
            $rules = $rules->withGitignore(false);
        }

        [$entries, $total, $ignored, $escaped] = $this->collect($baseDir, $jailRoot, $includeHidden, $rules);

        // collect() has already stopped at maxEntries, so every entry it
        // returned is printed and the not-shown remainder is a counter, not
        // a skipped loop.
        $output = '';
        foreach ($entries as $absolute) {
            $output .= $absolute . (is_dir($absolute) ? '/' : '') . "\n";
        }

        if ($total > count($entries)) {
            $output .= sprintf(
                "... [truncated: stopped after %d entries; %d more entries not shown. This list is PARTIAL, not the complete answer "
                . "— point path at a subdirectory to see its entries, or use Glob to search the tree by name.]\n",
                count($entries),
                $total - count($entries),
            );
        }

        $output = $this->truncateOutput($output, $this->maxOutputBytes);

        // Every note below is one bounded line and the ONLY place its fact
        // appears, so like Glob and Grep they go after the clip, behind the
        // budget's reach — an omission announcement the cap can sacrifice
        // recreates the confident-partial-answer failure the notes exist to
        // prevent.
        if ($ignored > 0) {
            $output = self::separated($output);
            $output .= sprintf(
                "... [gitignored: hid %d entr%s the project's .gitignore excludes. "
                . "Pass include_ignored: true to show them.]",
                $ignored,
                $ignored === 1 ? 'y' : 'ies',
            );
        }

        if ($escaped > 0) {
            $output = self::separated($output);
            $output .= sprintf(
                "... [outside: hid %d entr%s whose real path resolves outside the workspace root "
                . "(a symlink leaving the jail). Those targets are not part of this listing's boundary.]",
                $escaped,
                $escaped === 1 ? 'y' : 'ies',
            );
        }

        $undecidable = $rules->undecidableNote();
        if ($undecidable !== null) {
            $output = self::separated($output);
            $output .= $undecidable . "\n";
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $output,
            isError: false,
        );
    }

    /**
     * One pass over the directory whose RETENTION is bounded, not just its
     * reading: hidden/ignored filtering and the jail's realpath test still
     * run per entry — the ignored and escaped counts must be exact, so no
     * entry may dodge them — but surviving paths are no longer accumulated
     * and `usort`ed whole. Only the `maxEntries` smallest basenames are
     * kept, in a max-heap whose root is the largest name retained: a smaller
     * arrival evicts the root and takes its slot, a larger one is dropped on
     * the spot. Basenames are unique inside a directory, so that eviction
     * rule reproduces the strcmp prefix exactly — the listing still reads
     * like a directory and not like readdir()'s inode order, entry for
     * entry — while the memory a listing costs is O(maxEntries) instead of
     * O(directory size). The walk was never the cost the cap bounded (that
     * earlier docblock claimed it was); the collect-then-sort was.
     *
     * @return array{0: list<string>, 1: int, 2: int, 3: int} [up to maxEntries absolute paths in basename order, total entries that passed the filters, gitignored count, jail-escaped count]
     */
    private function collect(string $baseDir, ?string $jailRoot, bool $includeHidden, IgnoreRules $rules): array
    {
        // SplMaxHeap over [basename, path] pairs, compared by name so the
        // WORST kept entry sits at the root and eviction is one extract().
        // compare() points higher-priority (lexicographically larger) first,
        // which is exactly the entry the bounded window must lose.
        $kept = new class extends \SplMaxHeap {
            /** @param array{0: string, 1: string} $a @param array{0: string, 1: string} $b */
            protected function compare(mixed $a, mixed $b): int
            {
                return strcmp($a[0], $b[0]);
            }
        };

        $total = 0;
        $ignored = 0;
        $escaped = 0;

        $rootReal = $jailRoot === null ? false : realpath($jailRoot);

        foreach ((new StreamingDirectoryLister())->list($baseDir, $includeHidden) as $absolute) {
            $isDirectory = is_dir($absolute);

            if ($rules->ignores($absolute, $isDirectory)) {
                $ignored++;
                continue;
            }

            // Symlinks are ENTRIES either way — this tool lists, it does not
            // descend — but a link whose target escapes the jail is not inside
            // the vocabulary the jail is promising, so its entry goes (and the
            // count says so). Same realpath test Glob's withinRoot() applies
            // to matched paths; inlined only because the count feeds a note.
            // It stays a full-walk test for the same reason: the escaped count
            // must be exact, so no heap admission can defer it.
            if ($jailRoot !== null) {
                $real = realpath($absolute);
                if ($real === false
                    || ($rootReal !== false && $real !== $rootReal && !str_starts_with($real, $rootReal . '/'))
                ) {
                    $escaped++;
                    continue;
                }
            }

            $total++;

            $name = basename($absolute);
            if ($kept->count() < $this->maxEntries) {
                $kept->insert([$name, $absolute]);
            } elseif ($kept->count() > 0 && strcmp($name, $kept->top()[0]) < 0) {
                $kept->extract();
                $kept->insert([$name, $absolute]);
            }
        }

        // Extract drains largest-first, so reversing yields the same strcmp
        // order the old usort printed.
        $entries = [];
        while (!$kept->isEmpty()) {
            $entries[] = $kept->extract()[1];
        }

        return [array_reverse($entries), $total, $ignored, $escaped];
    }

    /**
     * Read a boolean tool argument — model JSON routinely sends `"true"`/1,
     * and a bare cast turns the string `"false"` into true (Glob's reason,
     * which is type-independent and applies verbatim to both flags here).
     */
    private static function flag(mixed $value): bool
    {
        return filter_var($value ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Make $output safe to append an annotation line to (the clip can end
     * mid-sentence on its marker with no trailing newline).
     */
    private static function separated(string $output): string
    {
        return ($output !== '' && !str_ends_with($output, "\n")) ? $output . "\n" : $output;
    }

    /**
     * The directory this instance is confined to: the injected sub-agent
     * worktree when there is one, else the workspace root (Glob/Grep's
     * precedence, and the reason a sub-agent's `Ls` cannot read a stale main
     * checkout).
     */
    private function jailRoot(): ?string
    {
        return $this->worktreeJail?->root() ?? $this->root;
    }
}

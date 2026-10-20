<?php

declare(strict_types=1);

namespace SugarCraft\Code\Attachments;

use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Per-project file list backing the `@` mention popup (plan_comparison TUI-1).
 *
 * The list is exactly what git considers present under the root — tracked and
 * non-ignored untracked alike — via the same `ls-files -z --cached --others
 * --exclude-standard` invocation {@see \SugarCraft\Code\RepoMap\RepoMapBuilder}
 * runs for the repo map, minus the code-extension filter: a mention can point
 * at any file, so every file is a candidate.
 *
 * One listing per canonical root per process (a memo, like the repo map's).
 * The consequence, stated honestly: a file created AFTER the first popup of
 * the session is invisible to the popup until the process restarts. The
 * unique-prefix completion in {@see FileMentions::complete()} is NOT memoized,
 * so it stays the cold-index fallback — a brand-new file it can see through
 * a live directory read remains completable by Tab even while the popup does
 * not list it. A non-git root lists nothing at all: the popup stays closed
 * and only prefix completion applies.
 *
 * Ranking is the already-depended sugar-fuzzy Smith-Waterman matcher, with
 * the basename scored FIRST and the full path second: typing `@in` floats
 * `src/Chat.php`-adjacent `index.php` above a deeper file whose directory
 * merely contains those letters. {@see self::rank()} memoizes per (root,
 * query) so the renderer may re-ask on every frame — the popup recomputes on
 * keystrokes only, never on scrolls (E646: no timers, no I/O on a repeat).
 */
final class MentionIndex
{
    /** Hard cap on the indexed file list, in the spirit of FileMentions::COMPLETION_SCAN_LIMIT. */
    public const MAX_INDEXED_FILES = 50_000;

    /** Candidates a single query hands to the popup, best-ranked first. */
    public const POPUP_LIMIT = 100;

    /** The last {@see RANK_MEMO_MAX} distinct (root, query) rank results kept. */
    private const RANK_MEMO_MAX = 64;

    /** @var array<string, list<string>> memo of {@see self::files()}, keyed by canonical root */
    private static array $filesMemo = [];

    /** @var array<string, list<array{path: string, subject: string, indices: list<int>, score: int}>> memo of {@see self::rank()}, keyed by root . "\0" . query */
    private static array $rankMemo = [];

    private static ?SmithWatermanMatcher $matcher = null;

    private function __construct()
    {
    }

    /**
     * The project's files, root-relative, path-sorted, capped.
     *
     * @return list<string>
     */
    public static function files(string $root): array
    {
        $key = realpath($root);
        if ($key === false || !is_dir($key)) {
            return [];
        }

        return self::$filesMemo[$key] ??= self::discover($key);
    }

    /**
     * The popup's rows for one typed partial: best match first.
     *
     * Each row carries the display path, the SUBJECT the highlight indices
     * address (the basename when the basename matched, the full path when
     * only the path did), the matched codepoint indices into that subject,
     * and the score. An empty partial ranks nothing: it returns the head of
     * the sorted listing so `@` alone opens the popup over the project.
     *
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    public static function rank(string $partial, string $root, int $limit = self::POPUP_LIMIT): array
    {
        $files = self::files($root);
        if ($files === [] || $limit < 1) {
            return [];
        }
        $root = realpath($root) ?: $root;

        if ($partial === '') {
            return self::head($files, $limit);
        }

        $memoKey = $root . "\0" . $partial;
        if (isset(self::$rankMemo[$memoKey])) {
            return array_slice(self::$rankMemo[$memoKey], 0, $limit);
        }

        $scored = self::scored($partial, $files);

        if (count(self::$rankMemo) >= self::RANK_MEMO_MAX) {
            unset(self::$rankMemo[array_key_first(self::$rankMemo)]);
        }

        // The memo keeps the FULL ranking and the ANSWER is sliced to this
        // call's limit — the same contract the memo-hit path above honours
        // (plan_comparison TUI-1): returning the store statement's value
        // handed a caller of POPUP_LIMIT up to MAX_INDEXED_FILES rows on the
        // first cold query, so the popup's wrap-count moved between the cold
        // frame and every warm one.
        return array_slice(self::$rankMemo[$memoKey] = $scored, 0, $limit);
    }

    /**
     * Rank an EXPLICIT file list with the popup's own matcher — the seam the
     * server's `fs.search` uses so a non-git workspace orders its walked files
     * by the same fuzzy score the git-backed {@see self::rank()} applies,
     * without re-walking (or duplicating) the ranking logic.
     *
     * Not memoized: the caller supplies the list, so there is no stable root
     * key to cache under, and the server caps the list it hands in.
     *
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    public static function rankPaths(string $partial, array $files, int $limit = self::POPUP_LIMIT): array
    {
        if ($files === [] || $limit < 1) {
            return [];
        }

        return $partial === '' ? self::head($files, $limit) : array_slice(self::scored($partial, $files), 0, $limit);
    }

    /**
     * The head of the listing, in listing order, for the empty partial:
     * `@` alone shows the front of the project rather than nothing.
     *
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function head(array $files, int $limit): array
    {
        return array_map(
            static fn(string $path): array => ['path' => $path, 'subject' => basename($path), 'indices' => [], 'score' => 0],
            array_slice($files, 0, $limit),
        );
    }

    /**
     * Every file the matcher accepts, best-ranked first. Basename is scored
     * before path so a basename hit outranks a deep directory that merely
     * contains the letters. usort is not stable, so the tiebreakers are
     * spelled out: score, then shorter path, then the listing's own order.
     *
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function scored(string $partial, array $files): array
    {
        $matcher = self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);
        $scored = [];
        foreach ($files as $path) {
            $base = basename($path);
            $rowBase = $matcher->match($partial, $base);
            if ($rowBase !== null) {
                $rowPath = $matcher->match($partial, $path);
                $scored[] = [
                    'path' => $path,
                    'subject' => $base,
                    'indices' => $rowBase->indices(),
                    // Basename primary, path affinity as the tiebreaker term.
                    'score' => 2 * $rowBase->score + ($rowPath?->score ?? 0),
                ];
                continue;
            }

            $rowPath = $matcher->match($partial, $path);
            if ($rowPath !== null) {
                $scored[] = ['path' => $path, 'subject' => $path, 'indices' => $rowPath->indices(), 'score' => $rowPath->score];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return [$b['score'], strlen($a['path']), $a['path']] <=> [$b['score'], strlen($b['path']), $b['path']];
        });

        return $scored;
    }

    /**
     * Drop the memos — the test seam, and the hook a future refresh command
     * would call to make freshly created files visible without a restart.
     */
    public static function forget(?string $root = null): void
    {
        if ($root === null) {
            self::$filesMemo = [];
            self::$rankMemo = [];
            self::$matcher = null;

            return;
        }

        $key = realpath($root);
        if ($key !== false) {
            unset(self::$filesMemo[$key]);
            foreach (array_keys(self::$rankMemo) as $memoKey) {
                if (str_starts_with($memoKey, $key . "\0")) {
                    unset(self::$rankMemo[$memoKey]);
                }
            }
        }
    }

    /**
     * THE COLD SYNC IS SYNCHRONOUS: the first `@` in a process pays one
     * `git ls-files` on the UI's own thread, bounded by
     * {@see GitRunner::DEFAULT_TIMEOUT_SECONDS} — the named 10-second ceiling
     * the plan_comparison TUI-1 audit recorded as acceptable (once per root;
     * the memo above is what makes it once). A root that git REFUSES is not
     * an error: the listing comes back empty and the popup shows nothing.
     *
     * @return list<string>
     */
    private static function discover(string $rootReal): array
    {
        $listing = GitRunner::new($rootReal)
            ->withInheritedGitEnv()
            ->withTimeout(GitRunner::DEFAULT_TIMEOUT_SECONDS)
            ->run('ls-files', '-z', '--cached', '--others', '--exclude-standard');
        if (!$listing['ok']) {
            // Not a git root (or git refused): the popup has nothing to show.
            return [];
        }

        $paths = array_values(array_unique(array_filter(
            explode("\0", $listing['stdout']),
            static fn(string $path): bool => $path !== '',
        )));
        sort($paths, SORT_STRING);

        return array_slice($paths, 0, self::MAX_INDEXED_FILES);
    }
}

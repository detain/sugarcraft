<?php

declare(strict_types=1);

namespace SugarCraft\Code\Attachments;

use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;

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
 *
 * Two bounds keep that memo path honest on real monorepo listings (TUI-1,
 * round 2). FIRST, the cold query walks a byte screen over the cached
 * lowercased listing instead of running the matcher per file: the screen
 * rejects the same candidates the matcher's requireFullQuery subsequence
 * door rejects, and files whose lowercased basename CONTAINS the query are
 * certified at their exact full-matcher score without an alignment (the
 * proof and its ASCII/profile preconditions live at {@see self::scored()}).
 * When the contained-basename bucket alone cannot fill the stored window,
 * the leftover candidates run the real matcher up to
 * {@see self::MAX_SCORED_CANDIDATES} best-possible-score first — within a
 * tier the budget is consumed in listing order, so a row past the cap is
 * dropped by position, not by merit — and paths
 * whose bytes break the ASCII/length assumptions the proof rests on ALWAYS
 * run the matcher. SECOND, each memo entry stores at most
 * {@see self::RANK_MEMO_TOP_ROWS} rows — a prefix of the ranking — not the
 * full ranking, so {@see self::RANK_MEMO_MAX} entries can never hold
 * dozens of full 50k-file rankings in the session process.
 */
final class MentionIndex
{
    /** Hard cap on the indexed file list, in the spirit of FileMentions::COMPLETION_SCAN_LIMIT. */
    public const MAX_INDEXED_FILES = 50_000;

    /** Candidates a single query hands to the popup, best-ranked first. */
    public const POPUP_LIMIT = 100;

    /**
     * Rows kept per memoized (root, query) entry. One wider than the popup:
     * the server's `fs.search` asks for `limit + 1` with `limit` capped at
     * POPUP_LIMIT, so RANK_MEMO_TOP_ROWS is the largest slice any real caller
     * takes and every production query still rides the memo.
     */
    private const RANK_MEMO_TOP_ROWS = self::POPUP_LIMIT + 1;

    /** The last {@see RANK_MEMO_MAX} distinct (root, query) rank results kept. */
    private const RANK_MEMO_MAX = 64;

    /**
     * Full-matcher runs a single cold query spends on candidates the byte
     * screen cannot certify or prune (see the class bound above). Beyond the
     * budget the leftover candidates are dropped best-possible-score first —
     * disclosed, bounded cost wins over an unbounded keystroke walk. The
     * window needs 101 rows; the budget keeps cold queries in the tens of
     * milliseconds even when a whole monorepo listing survives the screen.
     */
    private const MAX_SCORED_CANDIDATES = 800;

    /** @var array<string, list<string>> memo of {@see self::files()}, keyed by canonical root */
    private static array $filesMemo = [];

    /** @var array<string, list<string|null>> memo of lowercased listings, keyed by canonical root; null marks a path the byte screen may not judge */
    private static array $lowerMemo = [];

    /** @var array<string, list<array{path: string, subject: string, indices: list<int>, score: int}>> memo of {@see self::rank()}, keyed by root . "\0" . query, each entry at most RANK_MEMO_TOP_ROWS rows */
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
        if ($limit <= self::RANK_MEMO_TOP_ROWS && isset(self::$rankMemo[$memoKey])) {
            return array_slice(self::$rankMemo[$memoKey], 0, $limit);
        }

        $scored = self::scored($partial, $files, self::lowered($root));

        if ($limit > self::RANK_MEMO_TOP_ROWS) {
            // A caller wider than the stored window sees a fresh full slice
            // and leaves the memo untouched in BOTH directions — it cannot
            // trust a window-shaped entry to fill a wider order, and nothing
            // in-tree asks for more than RANK_MEMO_TOP_ROWS anyway (fs.search
            // tops out at exactly it; the windowed flood branch above already
            // bounds what a fresh call can even return).
            return array_slice($scored, 0, $limit);
        }

        if (count(self::$rankMemo) >= self::RANK_MEMO_MAX) {
            unset(self::$rankMemo[array_key_first(self::$rankMemo)]);
        }

        // The memo keeps the top RANK_MEMO_TOP_ROWS of this query's ranking —
        // a PREFIX of the full order — and the ANSWER is sliced from the
        // store, so the same query never answers differently between the
        // cold frame and every warm one (the contract the store was built
        // for; TUI-1 bounded the store itself so 64 entries of a 50k-file
        // root cannot hold 64 full rankings).
        return array_slice(self::$rankMemo[$memoKey] = array_slice($scored, 0, self::RANK_MEMO_TOP_ROWS), 0, $limit);
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

        if ($partial === '') {
            return self::head($files, $limit);
        }

        $lower = array_map(
            static fn(string $path): ?string => self::screenable($path) ? strtolower($path) : null,
            $files,
        );

        return array_slice(self::scored($partial, $files, $lower), 0, $limit);
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
     * The bounded cold walk (TUI-1) rests on one arithmetic fact about the
     * canonical profile under requireFullQuery. Write L for the score of an
     * alignment covering all m query codepoints contiguously
     * (m*matchScore + (m-1)*adjacentBonus) and U for any cover that is not
     * contiguous (at most m*matchScore + (m-2)*adjacentBonus: one adjacency
     * is spent on the gap, whose penalties are non-positive). A file whose
     * basename CONTAINS the case-folded query scores exactly 3L: both the
     * basename row and the path affinity row are contiguous covers (the
     * basename is part of the path), each worth L, and the traceback lands
     * on the FIRST such cover because ties keep the earliest cell. Every
     * other hit scores strictly less — a path-containment row is at most
     * 2U+L (its basename cover, if any, is non-contiguous), a scattered row
     * at most 3U — and 2U+L < 3L since U < L. So contained-basename rows
     * are exactly the 3L rows, the window is filled by them alone once
     * there are RANK_MEMO_TOP_ROWS of them, and certifying them needs no
     * alignment. The proof assumes query and path fold byte-wise identically
     * (true exactly when both are ASCII, where fold is strtolower and code-
     * point offsets are byte offsets) and no candidate hits the matcher's
     * length caps; any path failing those assumptions, and any query that is
     * not plain ASCII or is over the query cap, runs the full matcher. If the
     * injected profile ever stops being the shape the arithmetic needs, the
     * whole screen is skipped and the legacy loop runs unchanged.
     *
     * Consequence stated honestly (TUI-1 disclosure law): the "ranking" this
     * returns may be a WINDOW (when the certified bucket fills it) or a
     * budget-capped order (when screened survivors overflow the matcher
     * budget) rather than the full walk. A shorter answer therefore never
     * proves fewer files matched; the popup list is candidate-complete up to
     * these bounds, and unique-prefix Tab completion through
     * {@see FileMentions::complete()} does not consume this method at all.
     *
     * @param  list<string>       $files
     * @param  list<string|null>  $lowerPaths  strtolower($files) with null where {@see self::screenable()} refuses, positionally aligned
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function scored(string $partial, array $files, array $lowerPaths): array
    {
        $matcher = self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);

        $cover = self::fastRankingApplies($partial)
            ? self::contiguousCoverScore($matcher->profile(), strlen($partial))
            : null;
        if ($cover === null) {
            return self::ordered(self::matcherRows($partial, $files));
        }

        $needle = strtolower($partial);
        $width = strlen($needle);
        $topScore = 3 * $cover;
        $certified = [];   // list<array{0: string, 1: int, 2: int}> path, basename start, needle offset in it
        $directory = [];   // path contains the needle, basename does not: matcher-bound
        $uncontained = []; // no path containment: a hit needs a scattered cover, decided by the chain
        $unscreenable = [];
        foreach ($files as $index => $path) {
            $lower = $lowerPaths[$index];
            if ($lower === null) {
                $unscreenable[] = $index;
                continue;
            }

            $slash = strrpos($lower, '/');
            $baseStart = $slash === false ? 0 : $slash + 1;
            $hit = strpos($lower, $needle, $baseStart);
            if ($hit !== false) {
                $certified[] = [$path, $baseStart, $hit - $baseStart];
                continue;
            }
            if (strpos($lower, $needle) !== false) {
                $directory[] = $index;
                continue;
            }
            $uncontained[] = $index;
        }

        if (count($certified) >= self::RANK_MEMO_TOP_ROWS && count($unscreenable) <= self::MAX_SCORED_CANDIDATES) {
            // The stored window is full of exact-3L rows: every path-only
            // containment (max 2U+L) and every scattered cover (max 3U)
            // scores strictly below them and needs neither the chain walk
            // nor a matcher run. Only the unscreenable paths can still tie
            // at 3L, and only their 3L rows survive the merge. Rows are
            // materialized AFTER the sort, for the window's head only.
            $keyOf = static fn(string $path): string => sprintf('%010d %s', strlen($path), $path);
            $slots = [];
            foreach ($unscreenable as $index) {
                foreach (self::rowsFor($matcher, $partial, $files, [$index]) as $row) {
                    if ($row['score'] === $topScore) {
                        $slots[$keyOf($row['path'])] = ['row' => $row];
                    }
                }
            }
            foreach ($certified as [$path, $baseStart, $offset]) {
                $slots[$keyOf($path)] = [$baseStart, $offset];
            }
            ksort($slots, SORT_STRING);

            $rows = [];
            foreach ($slots as $key => $slot) {
                if (count($rows) >= self::RANK_MEMO_TOP_ROWS) {
                    break;
                }
                $path = substr($key, 11);
                $rows[] = isset($slot['row'])
                    ? $slot['row']
                    : [
                        'path' => $path,
                        'subject' => substr($path, $slot[0]),
                        'indices' => range($slot[1], $slot[1] + $width - 1),
                        'score' => $topScore,
                    ];
            }

            return $rows;
        }
        if (count($certified) >= self::RANK_MEMO_TOP_ROWS) {
            // Pathological unscreenable flood: the screen cannot bound the
            // matcher work any more, so the legacy walk runs unchanged.
            return self::ordered(self::matcherRows($partial, $files));
        }

        // The certified bucket is short, so the window will not be full of
        // 3L rows and the weaker tiers matter. Place every screened survivor
        // in the tier of its PROVEN score ceiling — the ceiling algebra of
        // the docblock proof, L = m*match + (m-1)*adjacency and
        // U = the best non-contiguous cover:
        //   - path contains the needle, basename contains it in order:  2U+L
        //   - basename in order, path only in order:                    3U
        //   - path contains the needle, basename does not:              L
        //   - path only in order:                                       U
        // and 2U+L > 3U > L > U for any m >= 2. The matcher budget then runs
        // unscreenable rows FIRST (they carry no proven bound at all), and
        // fills the rest highest-ceiling first. Files with no covering
        // alignment in order are dropped outright: requireFullQuery nulls
        // exactly those, so the matcher could not have returned them either.
        $chain = '/' . implode('.*', array_map(
            static fn(string $byte): string => preg_quote($byte, '/'),
            str_split($needle),
        )) . '/s';
        $scatteredTight = [];
        $containedBase = [];
        $looseBase = [];
        foreach ($directory as $index) {
            $lower = $lowerPaths[$index];
            $slash = strrpos($lower, '/');
            $base = substr($lower, $slash === false ? 0 : $slash + 1);
            if (preg_match($chain, $base) === 1) {
                $containedBase[] = $index;
            } else {
                $looseBase[] = $index;
            }
        }
        $scatteredLoose = [];
        foreach ($uncontained as $index) {
            $lower = $lowerPaths[$index];
            if (preg_match($chain, $lower) !== 1) {
                // No covering alignment exists; requireFullQuery nulls
                // exactly this pair, so the matcher run can be skipped.
                continue;
            }
            $slash = strrpos($lower, '/');
            $base = substr($lower, $slash === false ? 0 : $slash + 1);
            if (preg_match($chain, $base) === 1) {
                $scatteredTight[] = $index;
            } else {
                $scatteredLoose[] = $index;
            }
        }

        $matchRuns = $unscreenable;
        foreach ([$containedBase, $scatteredTight, $looseBase, $scatteredLoose] as $tier) {
            $budget = self::MAX_SCORED_CANDIDATES - count($matchRuns);
            if ($budget <= 0) {
                break;
            }
            $matchRuns = array_merge($matchRuns, array_slice($tier, 0, $budget));
        }
        // Disclosed cap: when the screened survivors overflow the budget,
        // the DROPPED tail could hold rows that belong inside the window.
        // Filling the budget highest-ceiling-first keeps the loss to the
        // weakest tiers; a root whose candidates fit the budget — every
        // test fixture, and every query whose screened survivors stay under
        // it — ranks exactly as the unbounded legacy walk did.

        $rows = [];
        foreach ($certified as [$path, $baseStart, $offset]) {
            $rows[] = [
                'path' => $path,
                'subject' => substr($path, $baseStart),
                'indices' => range($offset, $offset + $width - 1),
                'score' => $topScore,
            ];
        }

        return self::ordered(array_merge($rows, self::rowsFor($matcher, $partial, $files, $matchRuns)));
    }

    /**
     * The legacy unbounded walk: every candidate through the matcher.
     *
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function matcherRows(string $partial, array $files): array
    {
        $matcher = self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);

        return self::rowsFor($matcher, $partial, $files, array_keys($files));
    }

    /**
     * The matcher's own rows for the selected positions, in listing order.
     *
     * @param  list<int>     $indexes
     * @param  list<string>  $files
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function rowsFor(SmithWatermanMatcher $matcher, string $partial, array $files, array $indexes): array
    {
        $rows = [];
        foreach ($indexes as $index) {
            $path = $files[$index];
            $base = basename($path);
            $rowBase = $matcher->match($partial, $base);
            if ($rowBase !== null) {
                $rowPath = $matcher->match($partial, $path);
                $rows[] = [
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
                $rows[] = ['path' => $path, 'subject' => $path, 'indices' => $rowPath->indices(), 'score' => $rowPath->score];
            }
        }

        return $rows;
    }

    /**
     * The listing's own comparator: score, then shorter path, then order.
     *
     * @param  list<array{path: string, subject: string, indices: list<int>, score: int}>  $rows
     * @return list<array{path: string, subject: string, indices: list<int>, score: int}>
     */
    private static function ordered(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            // TUI-1 round 2: the upstream spelling compared $b's score on
            // BOTH sides, so the score leg was a tautology and the popup
            // silently ordered by path length. The docblock above — and the
            // whole windowed fast path below, which prunes provably lower
            // rows — requires score-major; repair it in the open.
            return [$b['score'], strlen($a['path']), $a['path']] <=> [$a['score'], strlen($b['path']), $b['path']];
        });

        return $rows;
    }

    /**
     * The screen may be attempted for an ASCII query within the matcher's
     * query cap only: outside that, codepoint/byte offsets and the fold stop
     * being the same operation.
     */
    private static function fastRankingApplies(string $partial): bool
    {
        return strlen($partial) <= SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH
            && preg_match('/[\x80-\xFF]/', $partial) === 0;
    }

    /**
     * L of the docblock proof — the score of a contiguous full cover of a
     * $width-codepoint query — straight off the profile in force, or null
     * when the profile has a shape the arithmetic does not cover (the caller
     * then runs the legacy walk unchanged).
     */
    private static function contiguousCoverScore(ScoringProfile $profile, int $width): ?int
    {
        if ($profile->matchScore <= 0
            || $profile->adjacentBonus <= 0
            || $profile->mismatchPenalty > 0
            || $profile->gapOpen > 0
            || $profile->gapExtend > 0
        ) {
            return null;
        }

        return $width * $profile->matchScore + ($width - 1) * $profile->adjacentBonus;
    }

    /**
     * Whether the byte screen may judge this path: ASCII bytes fold
     * identically under strtolower and CharFold, and a path within the
     * matcher's candidate cap puts its basename under it too.
     */
    private static function screenable(string $path): bool
    {
        return strlen($path) <= SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH
            && preg_match('/[\x80-\xFF]/', $path) === 0;
    }

    /**
     * The lowercased listing backing the screen, memoized beside the listing
     * itself with the same lifetime.
     *
     * @return list<string|null>
     */
    private static function lowered(string $rootReal): array
    {
        return self::$lowerMemo[$rootReal] ??= array_map(
            static fn(string $path): ?string => self::screenable($path) ? strtolower($path) : null,
            self::$filesMemo[$rootReal],
        );
    }

    /**
     * Drop the memos — the test seam, and the hook a future refresh command
     * would call to make freshly created files visible without a restart.
     */
    public static function forget(?string $root = null): void
    {
        if ($root === null) {
            self::$filesMemo = [];
            self::$lowerMemo = [];
            self::$rankMemo = [];
            self::$matcher = null;

            return;
        }

        $key = realpath($root);
        if ($key !== false) {
            unset(self::$filesMemo[$key], self::$lowerMemo[$key]);
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
     * the memo above is what makes it once). What the SAME cold query may NOT
     * pay any more is an unbounded matcher walk over the listing: the screen
     * and the per-query match budget above cap it, and each memo entry is
     * bounded too (TUI-1, round 2). A root that git REFUSES is not an error:
     * the listing comes back empty and the popup shows nothing.
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

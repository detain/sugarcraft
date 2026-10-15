<?php

declare(strict_types=1);

/**
 * Rename one SugarCraft library end-to-end per plan_rename.md (Step 0.3).
 *
 * WHY THIS EXISTS. The 52-lib rename is one mechanical transformation
 * repeated 45+ times, and every manual miss is a brand reference that
 * survives into master for tools/check-rename-residue.php to hunt down later.
 * The transformation therefore lives here once; each batch run is
 * `--dry-run`, read the printed plan, run, commit.
 *
 * USAGE
 *   php tools/rename-lib.php --old=<slug> [--dry-run] [--no-move] [--verbose] [--map=<path>]
 *
 * Resolves the first map entry whose "old" equals <slug>. The map is
 * tools/renames.json next to this file unless --map=<path> says otherwise;
 * SUGARCRAFT_RENAME_ROOT=<dir> overrides the repository root operated on —
 * the hook tools/tests/RenameLibTest.php uses to drive throwaway fixtures.
 *
 * PHASES (Step 0.3)
 *   1. preflight  — clean working tree; <old>/ exists and <new>/ does not
 *     (unless --no-move, or the tree is already in the moved state, which is
 *     detected and turns the run into a content-only repair or a clean
 *     no-op — the operation is idempotent by detection, not by luck); when
 *     ns_old != ns_new, no tracked file under any lib's src/ or tests/ tree
 *     or any composer.json may already contain the target namespace.
 *   2. move       — `git mv <old> <new>`, then every OTHER tracked path has
 *     each segment rewritten by the Part C.2 rules and `git mv`'d (longest
 *     paths first, mkdir -p for new parents); finally the entry "bins" map.
 *   3. content    — every non-excluded tracked text file, line by line,
 *     through rules R1..R7 of Part C.1, honouring the `@rename-keep` line
 *     skip; line endings and the trailing newline are preserved exactly.
 *   4. checks     — php -l on every rewritten .php and on <new>/bin/*,
 *     `composer validate --no-check-publish --no-check-lock` on
 *     <new>/composer.json (when composer exists — warnings must not fail it
 *     without --strict, per the AGENTS.md gotcha), and
 *     tools/check-one-type-per-file.php against the root.
 *
 * ROOT MANIFEST / CODECOV / DOCS DATA. Step 0.3 also names root
 * composer.json requires+repositories, codecov.yml and docs/_data as targets.
 * They need no separate logic: each of those references is a tracked text
 * file matching R3 (`"sugarcraft/candy-shine"`, `"../candy-shine"`, codecov
 * flags) or a tracked path matching C.2 (`docs/_data/<old>.{json,body.html}`),
 * so phases 2-3 fix them generically. composer.lock regeneration stays the
 * manual Procedure R5 step (`composer update 'sugarcraft/*' --no-install`).
 *
 * FLAGS
 *   --dry-run  print the planned renames and per-file per-rule replacement
 *              counts; write nothing and run no git commands. Matched-line
 *              dumps live behind --verbose (the plan's default is counts).
 *   --no-move  content rules only; never run `git mv` (merge/fold entries
 *              that relocate files by hand first — Step 0.3).
 *   --verbose  also print every rewritten line as `path:line [Rn] text`.
 *
 * EXIT CODES
 *   0 success — including the clean no-op when the tree is already renamed;
 *   1 post-check failure — the renames landed and a follow-up check objects;
 *     the tree is left in place for inspection, per Step 0.3;
 *   2 preflight refusal — dirty tree, unknown slug, missing <old>/,
 *     pre-existing <new>/, or a colliding target namespace. Nothing touched.
 *
 * NEVER-REWRITE (Part C.4). `SugarCraft`, `SUGARCRAFT_*` and the `sugarcraft/`
 * vendor prefix survive every batch BY CONSTRUCTION: no map entry has an old
 * slug whose derived token equals one of those forms, so no rule can ever
 * match them. Inventing a special case here would only drift from that
 * argument, so there is none.
 */

namespace SugarCraft\Tools;

final class RenameLib
{
    /** The line-skip sentinel of Part C.1 — a line carrying it is law. */
    public const KEEP_SENTINEL = '@rename-keep';

    /** Part C.3 exact-path exclusions (repo-root-relative, forward slashes). */
    private const EXCLUDED_EXACT = [
        'plan_rename.md',
        'tools/renames.json',
        'tools/rename-lib.php',
        'tools/check-rename-residue.php',
        'tools/tests/RenameLibTest.php',
        'tools/tests/CheckRenameResidueTest.php',
    ];

    /** Part C.3 directory-prefix exclusions. */
    private const EXCLUDED_PREFIXES = [
        'tools/tests/fixtures/rename/',
        'docs/old/',
        '.remember/',
        '.logs/',
        '.codenomad/',
    ];

    /** Part C.3 path-segment exclusions (dependency and VCS sandboxes). */
    private const EXCLUDED_SEGMENTS = ['vendor', 'node_modules', '.git'];

    /** Part C.3 basename exclusions — lockfiles are regenerated, not renamed. */
    private const EXCLUDED_BASENAMES = ['composer.lock', 'package-lock.json'];

    /**
     * Part C.3 binary extensions. ADOPTED READING (disclosed as a Step 0.3
     * ruling): these and NUL-bearing files are excluded from CONTENT
     * rewriting only — their paths still rename — because Part C.2 lists
     * media/icons/<old>.png and ansi/<old>/*.ansi as rename targets, and
     * C.3's own *.ansi bullet establishes this content-vs-path split.
     */
    private const BINARY_EXTENSIONS = [
        'gif', 'png', 'jpg', 'jpeg', 'ico', 'webp', 'phar',
        'ttf', 'otf', 'woff', 'woff2', 'mp4', 'zip', 'gz',
    ];

    /**
     * `--help` text. The `Environment:` block is asserted in both directions
     * by tools/tests/ToolsEnvRosterTest.php — keep it in sync with getenv().
     */
    private const HELP = <<<'TXT'
        Usage: php tools/rename-lib.php --old=<slug> [--dry-run] [--no-move] [--verbose] [--map=<path>]

        Options:
          --dry-run    print the planned renames and replacement counts, write nothing
          --no-move    apply content rules only; never run `git mv`
          --verbose    also dump every rewritten line as `path:line [Rn] text`
          --map=<path> alternate renames.json instead of the sibling of this script

        Environment:
          SUGARCRAFT_RENAME_ROOT  target repository root; defaults to the monorepo
                containing tools/. The documented test hook of Step 0.3, so the
                fixture suites can point the tool at throwaway mini-repos.

        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $old = null;
        $mapPath = null;
        $dryRun = false;
        $noMove = false;
        $verbose = false;
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('#^--old=(.+)$#', $arg, $m) === 1) {
                $old = $m[1];
            } elseif (preg_match('#^--map=(.+)$#', $arg, $m) === 1) {
                $mapPath = $m[1];
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--no-move') {
                $noMove = true;
            } elseif ($arg === '--verbose') {
                $verbose = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, self::HELP);

                return 0;
            } else {
                fwrite(STDERR, "rename-lib: unrecognised argument: $arg\n");

                return 2;
            }
        }
        if ($old === null || $old === '') {
            fwrite(STDERR, "rename-lib: --old=<slug> is required (the map is keyed by the OLD slug)\n");

            return 2;
        }

        $root = getenv('SUGARCRAFT_RENAME_ROOT');
        if (!is_string($root) || $root === '') {
            $root = dirname(__DIR__);
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            fwrite(STDERR, "rename-lib: root is not a directory: $root\n");

            return 2;
        }

        return self::run($resolved, $old, $dryRun, $noMove, $verbose, $mapPath ?? __DIR__ . '/renames.json');
    }

    public static function run(string $root, string $old, bool $dryRun, bool $noMove, bool $verbose, string $mapPath): int
    {
        $entries = self::loadEntries($mapPath);
        if (!is_array($entries)) {
            fwrite(STDERR, "rename-lib: $entries\n");

            return 2;
        }
        $entry = null;
        foreach ($entries as $candidate) {
            if (is_array($candidate) && ($candidate['old'] ?? null) === $old) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            fwrite(STDERR, "rename-lib: no map entry with old=\"$old\" in $mapPath\n");

            return 2;
        }
        $d = self::derive($entry, array_values(array_filter($entries, 'is_array')));
        if ($d['new'] === '') {
            fwrite(STDERR, "rename-lib: entry for old=\"$old\" has no new slug\n");

            return 2;
        }

        // Preflight #1: the tree must be clean, or a failure mid-run is
        // indistinguishable from someone else's work.
        $porcelain = self::porcelain($root);
        if ($porcelain !== '') {
            fwrite(STDERR, "rename-lib: working tree is not clean, refusing to start:\n$porcelain\n");

            return 2;
        }

        $oldExists = is_dir("$root/{$d['old']}");
        $newExists = is_dir("$root/{$d['new']}");
        $movedState = !$noMove && !$oldExists && $newExists;

        $tracked = self::trackedPaths($root);
        // With --no-move the target namespace legitimately exists — that is
        // the premise of the fold/merge and external-consumer flows the flag
        // exists for (Step 1.2: folding sugar-prompt into the live
        // SugarCraft\Forms namespace). The collision scan guards the in-flight
        // `git mv` path only, so the moved-state suppression widens to noMove.
        $problems = self::preflight($root, $d, $noMove || $movedState, $movedState || $noMove, $tracked);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                fwrite(STDERR, "rename-lib: preflight: $problem\n");
            }

            return 2;
        }

        $scanSet = array_values(array_filter($tracked, static fn(string $p): bool => !self::isExcludedPath($p)));
        [$contentPlan, $ruleTotals] = self::scan($root, $scanSet, $d);

        $pathPlan = [];  // paths outside <old>/: tracked path -> renamed path
        $innerPlan = []; // paths inside <old>/: post-dir-move path -> renamed path
        if (!$noMove) {
            $prefix = $d['old'] . '/';
            foreach ($scanSet as $path) {
                if (self::keepsItsName("$root/$path")) {
                    // The file's own content declares @rename-keep: it stays
                    // exactly as it is, name included (gen-docs redirect stubs
                    // are the canonical case — their whole purpose is to carry
                    // the old slug). The idempotent re-run must not try to
                    // move them onto the already-existing new-name page.
                    continue;
                }
                if ($oldExists && !$movedState && str_starts_with($path, $prefix)) {
                    // The whole-directory git mv carries the file; its INNER
                    // segments (bin/sugarcrush, docs/<old>-spec.md) still get
                    // the C.2 pass, planned against the post-move location.
                    $inner = substr($path, strlen($prefix));
                    $renamedInner = self::rewritePath($inner, $d);
                    if ($renamedInner !== null) {
                        $innerPlan["{$d['new']}/$inner"] = "{$d['new']}/$renamedInner";
                    }
                    continue;
                }
                $target = self::rewritePath($path, $d);
                if ($target !== null) {
                    $pathPlan[$path] = $target;
                }
            }
            $collisions = self::pathPlanCollisions($root, $pathPlan + $innerPlan, $tracked);
            if ($collisions !== []) {
                foreach ($collisions as $collision) {
                    fwrite(STDERR, "rename-lib: preflight: $collision\n");
                }

                return 2;
            }
        }

        $binsPlan = [];
        foreach ($d['bins'] as $binsOld => $binsNew) {
            if ((string) $binsOld === (string) $binsNew) {
                continue;
            }
            // Plan the bin move only while its source actually exists — pre-
            // move under <old>/, post-move under <new>/ — so the idempotent
            // re-run of an entry with a bins map still reads as a clean no-op
            // once the generic segment pass has already landed the target.
            if (is_file("$root/{$d['old']}/$binsOld") || is_file("$root/{$d['new']}/$binsOld")) {
                $binsPlan[] = [(string) $binsOld, (string) $binsNew];
            }
        }

        // Empty plan: the directory move is only ever pending while <old>/
        // exists (and --no-move never plans one), so "nothing planned" plus
        // "no move pending" is the whole test for a no-op — the moved state
        // being that no-op's most common flavour (the idempotent re-run).
        if ($pathPlan === [] && $innerPlan === [] && $binsPlan === [] && $contentPlan === [] && ($movedState || $noMove || !$oldExists)) {
            fwrite(STDOUT, sprintf(
                "rename-lib: %s -> %s: %s\n",
                $d['old'],
                $d['new'],
                $movedState ? 'already renamed — clean no-op' : 'no path or content matches — nothing to do',
            ));

            return 0;
        }

        $dirMove = !$noMove && !$movedState && $oldExists && !$newExists;
        if ($dryRun) {
            // A bin the generic segment pass already plans to move would print
            // (and count) twice; the execute path self-heals via its existence
            // check, so only the display needs the dedup.
            $displayBins = array_values(array_filter(
                $binsPlan,
                static fn(array $p): bool => ($innerPlan["{$d['new']}/{$p[0]}"] ?? null) !== "{$d['new']}/{$p[1]}",
            ));
            self::printPlan($d, $dirMove, $pathPlan + $innerPlan, $displayBins, $contentPlan, $ruleTotals, $verbose, true);

            return 0;
        }

        // Phase 2 — moves. Order is the plan's: directory, then per-path
        // longest-first, then the bins map.
        if ($dirMove) {
            self::gitMv($root, $d['old'], $d['new']);
            fwrite(STDOUT, "  moved   {$d['old']}/ -> {$d['new']}/\n");
        }
        $moves = $pathPlan + $innerPlan;
        uksort($moves, static fn(string $a, string $b): int => [strlen($b), $a] <=> [strlen($a), $b]);
        foreach ($moves as $from => $to) {
            if (!is_file("$root/$from") && !is_dir("$root/$from")) {
                continue; // a parent rename already carried it
            }
            if (is_file("$root/$to") || is_dir("$root/$to")) {
                fwrite(STDERR, "rename-lib: refusing to overwrite existing path: $to\n");

                return 1;
            }
            $parent = dirname("$root/$to");
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                fwrite(STDERR, "rename-lib: could not create parent directory: $parent\n");

                return 1;
            }
            self::gitMv($root, $from, $to);
            fwrite(STDOUT, "  renamed $from -> $to\n");
        }
        foreach ($binsPlan as [$binsOld, $binsNew]) {
            $src = "{$d['new']}/$binsOld";
            $dst = "{$d['new']}/$binsNew";
            if (!is_file("$root/$src")) {
                continue; // the generic segment pass already renamed it
            }
            if (is_file("$root/$dst")) {
                fwrite(STDERR, "rename-lib: refusing to overwrite existing bin path: $dst\n");

                return 1;
            }
            self::gitMv($root, $src, $dst);
            fwrite(STDOUT, "  renamed $src -> $dst\n");
        }

        // Phase 3 — content, re-scanned so path-context rules (R4 python,
        // R6 Lang.php) read final paths.
        $trackedAfter = self::trackedPaths($root);
        $scanSetAfter = array_values(array_filter($trackedAfter, static fn(string $p): bool => !self::isExcludedPath($p)));
        [$contentPlanAfter, $ruleTotals] = self::scan($root, $scanSetAfter, $d);
        $changedFiles = [];
        foreach ($contentPlanAfter as $path => $plan) {
            file_put_contents("$root/$path", $plan['contents']);
            $changedFiles[] = $path;
            $summary = self::formatCounts($plan['counts']);
            fwrite(STDOUT, "  wrote   $path  ($summary)\n");
            if ($verbose) {
                foreach ($plan['lines'] as $hit) {
                    fwrite(STDOUT, "      $path:{$hit['line']} [{$hit['tag']}] {$hit['text']}\n");
                }
            }
        }

        // Phase 4 — post-checks (Step 0.3: report failures, leave the tree).
        $checkProblems = self::postChecks($root, $d, $changedFiles);
        self::printPlanTotals($moves, $binsPlan, $changedFiles, $ruleTotals, false);
        if ($checkProblems !== []) {
            foreach ($checkProblems as $problem) {
                fwrite(STDERR, "rename-lib: check: $problem\n");
            }

            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array{0: array<string, array{contents:string, counts:array<string,int>, lines:list<array{line:int,tag:string,text:string}>}>, 1: array<string,int>}
     */
    public static function scan(string $root, array $paths, array $d): array
    {
        $rules = self::rules($d);
        $plan = [];
        $totals = [];
        foreach ($paths as $path) {
            $file = "$root/$path";
            if (!is_file($file)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            // R6 is path-gated, not token-gated: a Lang.php whose NAMESPACE
            // domain is lowercase (e.g. 'ansi') may hold none of the needle
            // spellings, so the cheap prefilter must never hide it.
            $isLang = self::isLangPath($path, (string) $d['old'], (string) $d['new']);
            if ((!$isLang && !self::mightMatch($contents, $d)) || self::isContentExcluded($path, $contents)) {
                continue;
            }
            $result = self::rewriteContents($contents, $path, $d, $rules);
            if ($result['contents'] === $contents) {
                continue;
            }
            foreach ($result['counts'] as $tag => $count) {
                $totals[$tag] = ($totals[$tag] ?? 0) + $count;
            }
            $plan[$path] = $result;
        }

        return [$plan, $totals];
    }

    /** Cheap substring gate before per-line rewriting — never a rule proxy. */
    public static function mightMatch(string $contents, array $d): bool
    {
        $needles = [
            $d['old'],
            $d['pascal_old'],
            $d['compact_old'],
            strtoupper((string) $d['compact_old']),
            self::upperSnake((string) $d['old']),
        ];
        if ($d['ns_old'] !== '') {
            $needles[] = $d['ns_old'];
        }
        foreach ($d['extra_literals'] as $from => $_) {
            $needles[] = (string) $from;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($contents, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array<string, mixed>> $allEntries
     *
     * @return array<string, mixed> derived name set (Part B.4)
     */
    public static function derive(array $entry, array $allEntries): array
    {
        $old = (string) ($entry['old'] ?? '');
        $new = (string) ($entry['new'] ?? '');
        $nsOld = (string) ($entry['ns_old'] ?? '');
        $nsNew = (string) ($entry['ns_new'] ?? $nsOld);

        // PROTECT (C.1): the map's explicit list plus, by the plan's
        // auto-derivation, any other entry's OLD slug prefixed O.'-'.
        $protect = array_values(array_filter((array) ($entry['protect'] ?? []), 'is_string'));
        foreach ($allEntries as $other) {
            $otherOld = $other['old'] ?? null;
            if (is_string($otherOld) && $otherOld !== $old && str_starts_with($otherOld, $old . '-') && !in_array($otherOld, $protect, true)) {
                $protect[] = $otherOld;
            }
        }

        return [
            'old' => $old,
            'new' => $new,
            'ns_old' => $nsOld,
            'ns_new' => $nsNew,
            'pascal_old' => self::pascalCase($old),
            'pascal_new' => self::pascalCase($new),
            'compact_old' => self::compact($old),
            'compact_new' => (string) ($entry['compact_ident_new'] ?? self::compact($new)),
            'env_old_prefixes' => array_values(array_unique([self::upperSnake($old) . '_', strtoupper(self::compact($old)) . '_'])),
            'env_new_prefix' => self::upperSnake($new) . '_',
            'protect' => $protect,
            'bins' => (array) ($entry['bins'] ?? []),
            'extra_literals' => (array) ($entry['extra_literals'] ?? []),
        ];
    }

    /** Part B.4: hyphen-split, ucfirst each part, join. */
    public static function pascalCase(string $slug): string
    {
        return implode('', array_map('ucfirst', explode('-', $slug)));
    }

    /** Part B.4: slug without separators. */
    public static function compact(string $slug): string
    {
        return str_replace('-', '', $slug);
    }

    /** Part B.4: hyphens to underscores, upper. */
    public static function upperSnake(string $slug): string
    {
        return strtoupper(str_replace('-', '_', $slug));
    }

    /**
     * The ordered rule transformers R1..R7 (Part C.1). Each entry is
     * ['tag' => 'Rn', 'apply' => fn(line, path): array{0:string,1:int}]
     * returning the rewritten line and the replacement count.
     *
     * @param array<string, mixed> $d
     *
     * @return list<array{tag: string, apply: \Closure(string,string):array{0:string,1:int}}>
     */
    public static function rules(array $d): array
    {
        $rules = [];

        if ($d['ns_old'] !== '' && $d['ns_old'] !== $d['ns_new']) {
            // R1 rewrites the namespace token while capturing the backslash
            // run, so the PHP single-backslash and composer-JSON
            // double-backslash spellings share one rule; the lookahead keeps
            // a DIFFERENT namespace (SugarCraft\ShineX) out of reach.
            $rules[] = self::regexRule('R1', '#SugarCraft(\\\\+)' . preg_quote((string) $d['ns_old'], '#') . '(?![A-Za-z0-9_])#', static fn(array $m): string => 'SugarCraft' . $m[1] . $d['ns_new']);
        }

        // R2, the PascalCase brand. The trailing class deliberately folds
        // SugarCrushWeb -> CandyCodeWeb and SugarBoxerTest ->
        // SugarPanelsTest, while SugarCrushx (lowercase continuation) stays.
        $rules[] = self::regexRule('R2', '#(?<![A-Za-z0-9_])' . preg_quote((string) $d['pascal_old'], '#') . '(?![a-z0-9])#', static fn(array $m): string => (string) $d['pascal_new']);

        // R3, the slug at word boundaries (so ./-/_ all terminate it), with
        // one negative lookahead per PROTECT longer slug.
        $rules[] = self::regexRule('R3', self::r3Pattern($d), static fn(array $m): string => (string) $d['new']);

        // R4, the compact identifier. Python files and python_port/ paths
        // take the compact NEW identity — a module name cannot carry hyphens;
        // everywhere else a bare compact run denotes the CLI, which is the
        // new slug. The lookbehind keeps $sugarcrushDir (and the `$> `
        // shell-form) out; the lookahead keeps sugarcrush_x out.
        $rules[] = [
            'tag' => 'R4',
            'apply' => static function (string $line, string $path) use ($d): array {
                $replacement = self::pythonScoped($path) ? (string) $d['compact_new'] : (string) $d['new'];
                $count = 0;
                $out = preg_replace(self::r4Pattern($d), addcslashes($replacement, '\\$'), $line, -1, $count);

                return [(string) $out, $count];
            },
        ];

        // R5, environment prefixes. SUGARCRAFT_* is left alone because it is
        // a different literal (C.4 holds by construction, see header).
        foreach ($d['env_old_prefixes'] as $envPrefix) {
            $rules[] = self::regexRule('R5', '#(?<![A-Za-z0-9_])' . preg_quote((string) $envPrefix, '#') . '(?=[A-Z0-9])#', static fn(array $m): string => (string) $d['env_new_prefix']);
        }

        // R6, the i18n domain literal, only in the lib's own src/Lang.php.
        // The domain is the last namespace segment lower-cased (B.4); taking
        // the tail segment is what keeps merge entries (ns_new "Mouse\Zones")
        // honest — the bare string would otherwise smuggle a backslash into
        // a quoted PHP constant.
        $nsParts = explode('\\', (string) $d['ns_new']);
        $domain = strtolower((string) end($nsParts));
        $rules[] = [
            'tag' => 'R6',
            'apply' => static function (string $line, string $path) use ($d, $domain): array {
                if (!self::isLangPath($path, (string) $d['old'], (string) $d['new'])) {
                    return [$line, 0];
                }
                $count = 0;
                $out = preg_replace_callback(
                    "#NAMESPACE(\s*)=(\s*)'[^']*'#",
                    static fn(array $m): string => 'NAMESPACE' . $m[1] . '=' . $m[2] . "'" . $domain . "'",
                    $line,
                    -1,
                    $count,
                );

                return [(string) $out, $count];
            },
        ];

        // R7, literal pairs contributed by the map entry.
        $extra = (array) $d['extra_literals'];
        if ($extra !== []) {
            $rules[] = [
                'tag' => 'R7',
                'apply' => static function (string $line, string $path) use ($extra): array {
                    $count = 0;
                    foreach ($extra as $from => $to) {
                        $line = str_replace((string) $from, (string) $to, $line, $replacements);
                        $count += $replacements;
                    }

                    return [$line, $count];
                },
            ];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $d
     * @param bool $pathContext C.2 names underscore-JOINED files as rename
     *        targets (`findings/plan_<old>.md`, `docs/repo_map/sugarcraft_<old>.md`,
     *        `docs/repo_map/update_sugarcraft_<old>.md`). Inside a path SEGMENT the
     *        underscore is a filename separator, not a word character, so the
     *        lookbehind relaxes to alphanumerics only. Content rules (C.1) stay
     *        strict — `_` there is a word character and `$a_<old>` style glue must
     *        never be rewritten. (Pilot honey-flap finding, Phase 3.)
     */
    public static function r3Pattern(array $d, bool $pathContext = false): string
    {
        $protect = '';
        foreach ($d['protect'] as $p) {
            if (is_string($p) && str_starts_with($p, (string) $d['old'] . '-')) {
                $protect .= '(?!' . preg_quote(substr($p, strlen((string) $d['old'])), '#') . '(?![A-Za-z0-9_]))';
            }
        }

        return '#(?<!' . ($pathContext ? '[A-Za-z0-9]' : '[A-Za-z0-9_]') . ')' . preg_quote((string) $d['old'], '#') . '(?![A-Za-z0-9_])' . $protect . '#';
    }

    /** @param array<string, mixed> $d */
    public static function r4Pattern(array $d, bool $pathContext = false): string
    {
        return '#(?<!' . ($pathContext ? '[A-Za-z0-9]' : '[A-Za-z0-9_$>]') . ')' . preg_quote((string) $d['compact_old'], '#') . '(?![A-Za-z0-9_])#';
    }

    /**
     * @param \Closure(array):string $to
     *
     * @return array{tag: string, apply: \Closure(string,string):array{0:string,1:int}}
     */
    private static function regexRule(string $tag, string $pattern, \Closure $to): array
    {
        return [
            'tag' => $tag,
            'apply' => static function (string $line, string $path) use ($pattern, $to): array {
                $count = 0;
                $out = preg_replace_callback($pattern, $to, $line, -1, $count);

                return [(string) $out, $count];
            },
        ];
    }

    /** R4 context: Python file or python_port/ path (Part C.1/C.2). */
    public static function pythonScoped(string $path): bool
    {
        return str_ends_with($path, '.py') || str_contains($path, 'python_port/');
    }

    /**
     * R6 context: src/Lang.php of the lib being renamed. Both the old and
     * new directory spellings count — under --dry-run the move has not
     * happened yet, and --no-move leaves files where they are (Step 0.3).
     */
    public static function isLangPath(string $path, string $old, string $new): bool
    {
        return $path === "$old/src/Lang.php" || $path === "$new/src/Lang.php";
    }

    /**
     * Part C.2: rewrite one path segment by R2/R3/R4 plus the ansi prefix
     * rule. The ansi corpus names `<old><digits>-...` files that R3's word
     * boundary deliberately refuses; C.2 lists those paths as targets, so in
     * ansi/ a leading old slug is replaced once by prefix.
     *
     * @param array<string, mixed> $d
     */
    public static function rewriteSegment(string $segment, array $d, bool $python, bool $ansiPrefixContext = false, bool $pathContext = false): string
    {
        $out = $segment;
        if ($ansiPrefixContext && $out !== (string) $d['new'] && str_starts_with($out, (string) $d['old'])) {
            $out = (string) $d['new'] . substr($out, strlen((string) $d['old']));
        }
        $out = (string) preg_replace_callback(
            '#(?<!' . ($pathContext ? '[A-Za-z0-9]' : '[A-Za-z0-9_]') . ')' . preg_quote((string) $d['pascal_old'], '#') . '(?![a-z0-9])#',
            static fn(array $m): string => (string) $d['pascal_new'],
            $out,
        );
        $out = (string) preg_replace_callback(
            self::r3Pattern($d, $pathContext),
            static fn(array $m): string => (string) $d['new'],
            $out,
        );
        $out = (string) preg_replace(
            self::r4Pattern($d, $pathContext),
            addcslashes($python ? (string) $d['compact_new'] : (string) $d['new'], '\\$'),
            $out,
        );

        return $out;
    }

    /**
     * Part C.2: the renamed path, or null when no segment changes.
     *
     * @param array<string, mixed> $d
     */
    public static function rewritePath(string $path, array $d): ?string
    {
        $segments = explode('/', $path);
        $python = self::pythonScoped($path);
        $ansi = ($segments[0] ?? '') === 'ansi';
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $segments[$i] = self::rewriteSegment($segment, $d, $python, $ansi && $i === $last && str_ends_with($segment, '.ansi'), true);
        }
        $new = implode('/', $segments);

        return $new === $path ? null : $new;
    }

    /**
     * True when a file's own content carries the `@rename-keep` sentinel
     * anywhere — the file (name included) is deliberate and must not be
     * moved by the path plan. Unreadable files fall through to planning
     * (fail toward visible conflict, not silent skip). Pilot honey-flap
     * finding, Phase 3: the idempotent re-run collided a gen-docs redirect
     * stub (self-tagged) with the live new-name page.
     */
    public static function keepsItsName(string $absolutePath): bool
    {
        if (!is_file($absolutePath)) {
            return false;
        }
        $contents = @file_get_contents($absolutePath);

        return \is_string($contents) && str_contains($contents, self::KEEP_SENTINEL);
    }

    /**
     * Part C.3 path exclusion (content AND rename for sandboxes/lockfiles;
     * {@see BINARY_EXTENSIONS} for the content-only reading of binaries).
     */
    public static function isExcludedPath(string $path): bool
    {
        if (in_array($path, self::EXCLUDED_EXACT, true)) {
            return true;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        $segments = explode('/', $path);
        $basename = (string) array_pop($segments);
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
                return true;
            }
        }
        if (in_array($basename, self::EXCLUDED_BASENAMES, true)) {
            return true;
        }

        // Built web assets: <slug>-web/dist/** is regenerated by npm, not renamed (C.3).
        return $segments !== [] && str_ends_with($segments[0], '-web') && in_array('dist', $segments, true);
    }

    /** Content-exclusion half of Part C.3. */
    public static function isContentExcluded(string $path, string $contents): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BINARY_EXTENSIONS, true) || $extension === 'ansi') {
            return true;
        }

        return str_contains(substr($contents, 0, 8192), "\0");
    }

    /**
     * Rewrite file contents line by line. explode/implode on "\n" preserves
     * CRLF tails and a missing (or present) trailing newline byte-for-byte.
     *
     * @param array<string, mixed> $d
     * @param list<array{tag:string, apply:\Closure(string,string):array{0:string,1:int}}> $rules
     *
     * @return array{contents: string, counts: array<string,int>, lines: list<array{line:int,tag:string,text:string}>}
     */
    public static function rewriteContents(string $contents, string $path, array $d, array $rules): array
    {
        $counts = [];
        $matched = [];
        $lines = explode("\n", $contents);
        foreach ($lines as $index => $line) {
            if (str_contains($line, self::KEEP_SENTINEL)) {
                continue;
            }
            $current = $line;
            foreach ($rules as $rule) {
                [$rewritten, $count] = ($rule['apply'])($current, $path);
                if ($count > 0) {
                    $counts[$rule['tag']] = ($counts[$rule['tag']] ?? 0) + $count;
                    $matched[] = ['line' => $index + 1, 'tag' => $rule['tag'], 'text' => $line];
                    $current = $rewritten;
                }
            }
            $lines[$index] = $current;
        }

        return ['contents' => implode("\n", $lines), 'counts' => $counts, 'lines' => $matched];
    }

    /**
     * Step 0.3 preflight. $skipDirCheck silences the <old>/<new> directory
     * shape rules (--no-move); $movedState additionally silences the
     * namespace-collision scan, because in the moved state the target
     * namespace legitimately exists — it is what the first run wrote, and
     * under --no-move it is what the fold/merge target already is (Step 1.2).
     *
     * @param array<string, mixed> $d
     * @param list<string>         $tracked
     *
     * @return list<string>
     */
    public static function preflight(string $root, array $d, bool $skipDirCheck, bool $movedState, array $tracked): array
    {
        $problems = [];
        if (!$skipDirCheck) {
            $oldExists = is_dir("$root/{$d['old']}");
            $newExists = is_dir("$root/{$d['new']}");
            if (!$oldExists && !$newExists) {
                $problems[] = "neither {$d['old']}/ nor {$d['new']}/ exists at $root";
            } elseif ($oldExists && $newExists) {
                $problems[] = "both {$d['old']}/ and {$d['new']}/ exist — refusing to guess";
            }
        }

        if (!$movedState && $d['ns_old'] !== '' && $d['ns_old'] !== $d['ns_new']) {
            $single = 'SugarCraft\\' . str_replace('\\\\', '\\', (string) $d['ns_new']) . '\\';
            $double = str_replace('\\', '\\\\', $single);
            foreach ($tracked as $path) {
                if (!self::isCodeScopedPath($path) || self::isExcludedPath($path)) {
                    continue;
                }
                $body = @file_get_contents("$root/$path");
                if (is_string($body) && (str_contains($body, $single) || str_contains($body, $double))) {
                    $problems[] = "target namespace SugarCraft\\{$d['ns_new']}\\ already present in $path — a rename is in flight or the target is taken";
                }
            }
        }

        return $problems;
    }

    /** Code scope of the namespace-collision preflight (Step 0.3 #1). */
    public static function isCodeScopedPath(string $path): bool
    {
        if (preg_match('#(^|/)[^/]+/(src|tests)/#', $path) === 1) {
            return true;
        }

        return basename($path) === 'composer.json';
    }

    /**
     * Collision guard for the path plan: two sources may not land on one
     * target, and no target may already be tracked.
     *
     * @param array<string,string> $pathPlan
     * @param list<string>         $tracked
     *
     * @return list<string>
     */
    public static function pathPlanCollisions(string $root, array $pathPlan, array $tracked): array
    {
        $problems = [];
        $trackedSet = array_fill_keys($tracked, true);
        $targets = [];
        foreach ($pathPlan as $from => $to) {
            if (isset($targets[$to])) {
                $problems[] = "two paths rename to $to ({$targets[$to]} and $from)";
            }
            $targets[$to] = $from;
            if (isset($trackedSet[$to])) {
                $problems[] = "rename target $to is already tracked";
            } elseif (file_exists("$root/$to")) {
                $problems[] = "rename target $to already exists on disk (untracked residue?)";
            }
        }

        return $problems;
    }

    /**
     * Step 0.3 phase 4. Returns problem descriptions; empty means green.
     *
     * @param array<string, mixed> $d
     * @param list<string>         $changedFiles root-relative paths, post-move
     *
     * @return list<string>
     */
    public static function postChecks(string $root, array $d, array $changedFiles): array
    {
        $problems = [];

        $toLint = [];
        foreach ($changedFiles as $path) {
            if (str_ends_with($path, '.php')) {
                $toLint[] = $root . '/' . $path;
            }
        }
        if (is_dir("$root/{$d['new']}/bin")) {
            foreach ((array) scandir("$root/{$d['new']}/bin") as $entry) {
                if (is_string($entry) && $entry[0] !== '.' && is_file("$root/{$d['new']}/bin/$entry")) {
                    $toLint[] = "$root/{$d['new']}/bin/$entry";
                }
            }
        }
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        foreach (array_unique($toLint) as $file) {
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                $problems[] = 'php -l failed: ' . implode(' ', $out);
            }
            $out = [];
        }

        exec('command -v composer 2>/dev/null', $found, $which);
        if ($which === 0 && $found !== [] && is_file("$root/{$d['new']}/composer.json")) {
            exec('composer validate --no-check-publish --no-check-lock ' . escapeshellarg("$root/{$d['new']}/composer.json") . ' 2>&1', $composerOut, $rc);
            if ($rc !== 0) {
                $problems[] = 'composer validate failed: ' . implode(' ', $composerOut);
            }
        }

        exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/check-one-type-per-file.php') . ' --root=' . escapeshellarg($root) . ' 2>&1', $typeOut, $rc);
        if ($rc !== 0) {
            $problems[] = 'check-one-type-per-file failed: ' . implode(' | ', $typeOut);
        }

        return $problems;
    }

    /**
     * @param array<string, mixed>              $d
     * @param array<string,string>              $moves        planned path renames (outer + inner)
     * @param list<array{0:string,1:string}>   $binsPlan
     * @param array<string, array{contents:string, counts:array<string,int>, lines:list}> $contentPlan
     * @param array<string,int>                 $ruleTotals
     */
    private static function printPlan(array $d, bool $dirMove, array $moves, array $binsPlan, array $contentPlan, array $ruleTotals, bool $verbose, bool $dryRun): void
    {
        fwrite(STDOUT, sprintf("rename-lib: %s -> %s%s\n", $d['old'], $d['new'], $dryRun ? ' [dry-run, nothing written]' : ''));
        if ($dirMove) {
            fwrite(STDOUT, "  rename  {$d['old']}/ -> {$d['new']}/\n");
        }
        foreach ($moves as $from => $to) {
            fwrite(STDOUT, "  rename  $from -> $to\n");
        }
        foreach ($binsPlan as [$binsOld, $binsNew]) {
            fwrite(STDOUT, "  rename  {$d['new']}/$binsOld -> {$d['new']}/$binsNew\n");
        }
        foreach ($contentPlan as $path => $plan) {
            fwrite(STDOUT, "  content $path  (" . self::formatCounts($plan['counts']) . ")\n");
            if ($verbose) {
                foreach ($plan['lines'] as $hit) {
                    fwrite(STDOUT, "      $path:{$hit['line']} [{$hit['tag']}] {$hit['text']}\n");
                }
            }
        }
        self::printPlanTotals($moves, $binsPlan, array_keys($contentPlan), $ruleTotals, $dryRun);
    }

    /**
     * @param array<string,string>            $moves
     * @param list<array{0:string,1:string}> $binsPlan
     * @param list<string>                    $contentFiles
     * @param array<string,int>               $ruleTotals
     */
    private static function printPlanTotals(array $moves, array $binsPlan, array $contentFiles, array $ruleTotals, bool $dryRun): void
    {
        $perRule = [];
        ksort($ruleTotals);
        foreach ($ruleTotals as $tag => $count) {
            $perRule[] = "$tag=$count";
        }
        $paths = count($moves) + count($binsPlan);
        $line = sprintf(
            "rename-lib: %s%d file%s to change, %d path%s to rename",
            $dryRun ? 'plan: ' : 'done: ',
            count($contentFiles),
            count($contentFiles) === 1 ? '' : 's',
            $paths,
            $paths === 1 ? '' : 's',
        );
        if ($perRule !== []) {
            $line .= ' (replacements: ' . implode(' ', $perRule) . ')';
        }
        fwrite(STDOUT, $line . "\n");
    }

    /** @param array<string,int> $counts */
    private static function formatCounts(array $counts): string
    {
        ksort($counts);
        $parts = [];
        foreach ($counts as $tag => $count) {
            $parts[] = "$tag:$count";
        }

        return implode(' ', $parts);
    }

    /**
     * The map is tools/renames.json: an object with an "entries" list (the
     * "$comment" sibling explains its own fields); a bare top-level list is
     * accepted too, so a hand-written --map fixture needs no ceremony.
     *
     * @return list<array<string, mixed>>|string error message on bad input
     */
    public static function loadEntries(string $mapPath): array|string
    {
        $raw = @file_get_contents($mapPath);
        if (!is_string($raw)) {
            return "cannot read rename map: $mapPath";
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
            $decoded = $decoded['entries'];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return "rename map is not a JSON list of entries: $mapPath";
        }

        return $decoded;
    }

    /** @return list<string> */
    public static function trackedPaths(string $root): array
    {
        exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("git ls-files failed in $root (rc $rc) — is it a git repository?");
        }
        $joined = implode("\0", $out);

        return array_values(array_filter(explode("\0", $joined), static fn(string $p): bool => $p !== ''));
    }

    public static function porcelain(string $root): string
    {
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("git status failed in $root (rc $rc)");
        }

        return trim(implode("\n", $out));
    }

    /** Run one `git mv`, refusing to fail silently mid-plan. */
    private static function gitMv(string $root, string $from, string $to): void
    {
        exec('git -C ' . escapeshellarg($root) . ' mv -- ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("git mv $from -> $to failed: " . implode(' ', $out));
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    exit(RenameLib::main($argv));
}

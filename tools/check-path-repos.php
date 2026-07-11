<?php

declare(strict_types=1);

/**
 * Path-repo closure check for the SugarCraft monorepo.
 *
 * For every lib, this script walks the FULL TRANSITIVE `sugarcraft/*`
 * require graph (each required sibling's composer.json is read to discover
 * the next level — all siblings are local path-repos, so no version solving
 * is needed, just name collection) and verifies a corresponding path-repo
 * entry exists in that lib's `repositories[]` (type=path, url="../<dep>")
 * for EVERY transitively-required sibling. Without the full closure a fresh
 * `composer install` cannot resolve the symlinks and falls back to the VCS
 * remote (which fails for unpublished libs). Catches the CLAUDE.md gotcha:
 *
 *   > New transitive @dev deps need their path-repo added to every
 *   > consuming lib's repositories[].
 *
 * Historically this checker only validated a lib's DIRECT requires, so a
 * gap two hops deep (e.g. sugar-glow → sugar-bits → candy-forms) slipped
 * through and broke fresh installs. It now resolves the transitive set and
 * reports the dependency path that introduced each missing entry.
 *
 * Exits 0 on clean closure, 1 with a printed report on any drift.
 *
 * With --fix: auto-inserts missing path-repo entries (direct AND transitive)
 * and exits 0 if every issue was fixable. With --help: print usage and exit 0.
 *
 * Recognized dev-constraint forms (all require path-repo closure since
 * they pin a moving HEAD inside the monorepo):
 *
 *   - `@dev`              — bare alias for `dev-{default-branch}`
 *   - `dev-master`        — explicit branch alias (most common in this repo)
 *   - `dev-main`, `dev-*` — any branch alias
 *   - `^1.0@dev` / `*@dev` — version constraint pinned to dev stability
 *
 * Stable Packagist constraints (`^1.0`, `~2.3`, etc.) are skipped — those
 * resolve via VCS/Packagist and don't need a path-repo.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P4.5)
 */

// Allow override via env for testing scenarios (e.g. fixture dirs).
$root = \getenv('SUGARCRAFT_CHECK_PATH_REPOS_ROOT');
if ($root !== false && $root !== '') {
    $root = \realpath($root);
    if ($root === false) {
        \fwrite(\STDERR, "tools/check-path-repos.php: SUGARCRAFT_CHECK_PATH_REPOS_ROOT is not a valid path\n");
        exit(2);
    }
} else {
    $root = \realpath(__DIR__ . '/..');
    if ($root === false) {
        \fwrite(\STDERR, "tools/check-path-repos.php: cannot resolve monorepo root\n");
        exit(2);
    }
}

$fix = false;
$help = false;
// --strict-closure flags EVERY transitive gap regardless of Packagist
// availability (the pre-1.0 ideal: full local path-repo closure everywhere).
// Default behaviour only flags a gap when the dep is ALSO unresolvable via
// Packagist, which models how Composer actually resolves today and keeps the
// signal focused on genuinely-broken fresh installs (e.g. an unpublished lib
// like a freshly-extracted candy-forms). --no-network forces offline mode:
// when a dep's Packagist status can't be determined it is assumed published
// (no false positives), unless --strict-closure is also given.
$strictClosure = false;
$noNetwork = false;
// --unused is an opt-in, READ-ONLY pass (composes with nothing else — it prints
// its own dead-dependency report and exits). It does NOT change the default
// closure-check behaviour, so existing CI invocations are unaffected.
$unused = false;

foreach ($_SERVER['argv'] as $arg) {
    if ($arg === '--fix') {
        $fix = true;
    } elseif ($arg === '--strict-closure') {
        $strictClosure = true;
    } elseif ($arg === '--no-network') {
        $noNetwork = true;
    } elseif ($arg === '--unused') {
        $unused = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $help = true;
    }
}

if ($help) {
    \fwrite(\STDOUT, <<<'EOF'
Usage: php tools/check-path-repos.php [options]

Checks path-repo closure for the SugarCraft monorepo.

For every lib, walk the FULL TRANSITIVE `sugarcraft/*` require graph (each
required sibling's composer.json is read to find the next level) and verify a
corresponding path-repo entry exists in that lib's repositories[] for every
transitively-required sibling pinned to a dev constraint (`@dev`, `dev-master`,
`dev-main`, `dev-*`, or `^x@dev`):

    { "type": "path", "url": "../<dep>", "options": { "symlink": true } }

A transitive gap is reported when a reachable sibling has NO path-repo entry
AND cannot be resolved another way. By default a dep that is published on
Packagist is treated as resolvable (Composer falls back to it), so only
genuinely-unresolvable gaps — e.g. an unpublished, freshly-extracted lib — are
flagged. Pass --strict-closure to demand a local path-repo for the FULL
transitive closure regardless of Packagist (the pre-1.0 ideal).

The inverse check — `--unused` — walks the SAME dev-pinned require graph but
hunts DEAD path-repo deps instead of missing ones. For every direct sugarcraft/*
require it resolves the dep's real PSR-4 prefix(es) from the DEP's own
composer.json and greps the consuming lib's src/ for them; a require with zero
src references is a prune candidate, classified by whether the dep is still
pulled in transitively:

  PRUNE_REQUIRE_KEEP_REPO  Unused directly but still reachable via another
                           require — drop the `require`, KEEP the repo entry.
  PRUNE_REQUIRE_AND_REPO   Unused directly AND unreachable transitively — drop
                           BOTH the require and the repositories[] entry.
  PRUNE_REPO_ONLY          A repositories[] path entry that is neither a direct
                           require nor in any direct require's transitive
                           closure (a lingering dead entry).

--unused is read-only (no auto-prune) and NOT wired into CI; it prints a per-lib
report and exits 1 on any finding. Each flagged require is annotated with
`tests_uses: yes|no` (whether the lib's tests/ still reference the dep — a "yes"
means the prune is a move-to-require-dev, not a delete). Findings are CANDIDATES:
confirm by hand before pruning (a dep referenced only via a string class-name or
composer script, not a namespace, will read as unused here).

Options:
  --fix             Auto-insert missing path-repo entries (direct AND
                    transitive) into affected composer.json files. Idempotent
                    when omitted (reports only).
  --strict-closure  Flag every transitive gap even if the dep is on Packagist.
  --no-network      Skip Packagist HEAD checks; assume unknown deps are
                    published (combine with --strict-closure for full offline
                    closure enforcement).
  --unused          Report DEAD path-repo deps (unused requires + lingering repo
                    entries) instead of missing ones. Read-only; exits 1 on any
                    finding. Runs on its own — ignores the other flags.
  --help            Show this usage message.

Exit codes:
  0  No issues found (or --fix succeeded for all issues; or --unused clean)
  1  Issues detected (closure drift, or --unused prune candidates)
  2  Fatal error (cannot resolve monorepo root)

EOF
    );
    exit(0);
}

$libs = \glob($root . '/*/composer.json') ?: [];
$issues = [];
$libsScanned = 0;
$fixedCount = 0;

// In --fix mode we collect fix requests rather than applying them mid-scan,
// so that we only report issues AFTER all fixes are applied successfully.
// Structure: [slug => ['manifestPath' => ..., 'missingRepos' => [...]], ...]
$fixRequests = [];

/**
 * Detect a dev-stability constraint that pins a moving HEAD inside the
 * monorepo: bare `@dev`, branch aliases (`dev-master`, `dev-main`, …), or
 * version-constrained dev (`^1.0@dev`). All require a path-repo for symlink
 * resolution. Stable Packagist constraints are skipped.
 */
$isDevConstraint = static function (string $constraint): bool {
    $trimmed = \trim($constraint);
    return $trimmed === '@dev'
        || \str_starts_with($trimmed, 'dev-')
        || \str_ends_with($trimmed, '@dev');
};

$skipDirs = ['vendor', 'node_modules', 'docs', 'plans', 'tools', 'scripts'];

// ---------------------------------------------------------------------------
// Pass 1 — load every manifest, record its dev-pinned sugarcraft/* requires.
// This builds the dependency graph used to compute transitive closures. Every
// sibling lib is loaded (even those with no requires) so the walker can resolve
// a dep slug → its own requires without re-reading from disk.
// ---------------------------------------------------------------------------

/** @var array<string, array{slug:string, manifestPath:string, manifest:array<string,mixed>, repos:mixed, devDeps:array<string,string>}> $libData keyed by slug */
$libData = [];

foreach ($libs as $manifestPath) {
    $slug = \basename(\dirname($manifestPath));
    // Skip vendor + bootstrap + docs scaffolds — they are not real libs.
    if (\in_array($slug, $skipDirs, true)) {
        continue;
    }

    $json = @\file_get_contents($manifestPath);
    if ($json === false) {
        $issues[] = "{$slug}: unreadable composer.json";
        continue;
    }
    $manifest = \json_decode($json, true);
    if (!\is_array($manifest)) {
        $issues[] = "{$slug}: invalid JSON in composer.json";
        continue;
    }

    // Collect sugarcraft/* dev-pinned deps from a require block: slug=>constraint.
    $collectDevDeps = static function ($requires) use ($isDevConstraint): array {
        $out = [];
        foreach ((array) $requires as $name => $constraint) {
            if (!\is_string($name) || !\is_string($constraint)) {
                continue;
            }
            if (!\str_starts_with($name, 'sugarcraft/') || !$isDevConstraint($constraint)) {
                continue;
            }
            $out[\substr($name, \strlen('sugarcraft/'))] = $constraint;
        }
        return $out;
    };

    // $devDeps = production `require`; $testDeps = `require-dev`. The closure
    // checker only walks production requires (that is the fresh-install path),
    // but require-dev deps ALSO need their path-repo (+ their production
    // closure), so --unused tracks them separately to avoid flagging a
    // still-needed test harness like candy-testing as a dead entry.
    $devDeps = $collectDevDeps($manifest['require'] ?? []);
    $testDeps = $collectDevDeps($manifest['require-dev'] ?? []);

    $libData[$slug] = [
        'slug' => $slug,
        'manifestPath' => $manifestPath,
        'manifest' => $manifest,
        'repos' => $manifest['repositories'] ?? [],
        'devDeps' => $devDeps,
        'testDeps' => $testDeps,
    ];
}

/**
 * Resolve the full transitive set of dev-pinned sugarcraft/* siblings reachable
 * from $startSlug (excluding $startSlug itself). Returns depSlug => path-string,
 * where the path records the first chain that introduced the dep (e.g.
 * "sugar-bits -> candy-forms") for actionable reporting. Cycles (candy-core ⇄
 * candy-pty) are handled via the visited set.
 *
 * @param array<string, array{devDeps:array<string,string>}> $libData
 * @return array<string, string>
 */
$transitiveDeps = static function (string $startSlug, array $libData): array {
    /** @var array<string, string> $found depSlug => introducing-path */
    $found = [];
    // BFS queue of [slug, pathPrefix].
    $queue = [[$startSlug, $startSlug]];

    while ($queue !== []) {
        [$current, $path] = \array_shift($queue);
        $deps = $libData[$current]['devDeps'] ?? [];
        foreach ($deps as $depSlug => $_constraint) {
            if ($depSlug === $startSlug) {
                continue; // self-cycle — never needs a path-repo to itself.
            }
            if (isset($found[$depSlug])) {
                continue; // already recorded via an earlier (shorter) path.
            }
            $childPath = $path . ' -> ' . $depSlug;
            $found[$depSlug] = $childPath;
            // Recurse only into siblings we know about; unknown slugs are
            // either external or absent and reported separately on lookup.
            if (isset($libData[$depSlug])) {
                $queue[] = [$depSlug, $childPath];
            }
        }
    }

    return $found;
};

// ---------------------------------------------------------------------------
// --unused — dead path-repo dependency detector (opt-in; read-only).
//
// The default checker hunts MISSING path-repo entries; this is the inverse pass
// — it hunts DEAD ones. For each lib and each of its DIRECT dev-pinned
// sugarcraft/* production requires it resolves the dep's real PSR-4 prefix(es)
// from the DEP's OWN composer.json (never guessed from the slug) and searches
// the CONSUMING lib's src/ for any of them. A require with zero src references
// is a prune candidate, classified by whether the dep is still pulled in when
// that one require is dropped — reachable from the lib's OTHER production
// requires PLUS its require-dev deps (each walked over production `require`
// edges): reachable ⇒ PRUNE_REQUIRE_KEEP_REPO (drop `require`, keep the repo),
// unreachable ⇒ PRUNE_REQUIRE_AND_REPO (drop both). Separately, a repositories[]
// path entry whose target is neither a require, a require-dev, nor in the
// production closure of either is PRUNE_REPO_ONLY (a lingering dead entry).
//
// require-dev matters: a test harness like candy-testing appears only in
// require-dev, yet its path-repo (and its production closure) is genuinely
// needed for `composer install --dev`. Ignoring require-dev would wrongly flag
// ~20 such entries as dead, so both roots feed the reachability walk. Each
// flagged require is annotated `tests_uses: yes|no` (does tests/ still reference
// it — a "yes" means move-to-require-dev, not delete).
//
// Namespace matching runs in PHP via str_contains against the literal
// single-backslash prefix (e.g. "SugarCraft\Core\"), sidestepping shell/grep
// backslash-escaping entirely. This mode is READ-ONLY (no --fix), runs
// independently of the other flags, and exits 1 on any finding, 0 when clean.
// ---------------------------------------------------------------------------
if ($unused) {
    // Slim dependency graph (slug => devDeps) so closure walks don't copy the
    // full manifests on every excluded-root recomputation.
    $graph = [];
    foreach ($libData as $s => $d) {
        $graph[$s] = ['devDeps' => $d['devDeps']];
    }

    // Namespace prefix(es) a dep declares in its OWN autoload.psr-4. Keys are
    // already single-backslash, trailing-backslash forms — exactly how a `use`
    // / FQCN reference appears in source, so they match literally.
    $psr4PrefixesOf = static function (string $depSlug) use ($libData): array {
        $psr4 = $libData[$depSlug]['manifest']['autoload']['psr-4'] ?? null;
        if (!\is_array($psr4)) {
            return [];
        }
        return \array_values(\array_filter(\array_keys($psr4), 'is_string'));
    };

    // Recursively collect *.php files under $dir that reference ANY of $prefixes
    // (literal substring). Returns matching paths so callers can eyeball them.
    $namespaceHits = static function (string $dir, array $prefixes): array {
        if ($prefixes === [] || !\is_dir($dir)) {
            return [];
        }
        $hits = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = @\file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (\str_contains($contents, $prefix)) {
                    $hits[] = $file->getPathname();
                    break;
                }
            }
        }
        return $hits;
    };

    // dep slug => url for every type=path repositories[] entry in a lib.
    $pathRepoTargetsOf = static function ($repos): array {
        $reposArray = [];
        if (\is_array($repos) && $repos !== []) {
            if (\array_keys($repos) === \range(0, \count($repos) - 1)) {
                $reposArray = $repos;
            } else {
                foreach ($repos as $repo) {
                    if (\is_array($repo)) {
                        $reposArray[] = $repo;
                    }
                }
            }
        }
        $targets = [];
        foreach ($reposArray as $repo) {
            if (!\is_array($repo) || ($repo['type'] ?? null) !== 'path') {
                continue;
            }
            $url = (string) ($repo['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $targets[\basename(\rtrim($url, '/'))] = $url;
        }
        return $targets;
    };

    // Everything reachable from a set of root slugs by following production
    // `require` edges, INCLUDING the roots themselves. Returns slug =>
    // introducing-path (roots map to their own name). This models what a fresh
    // `composer install` must resolve path-repos for: the lib's own requires,
    // its require-dev deps, and the full production closure of both. A repo
    // entry is dead only when its target is NOT in this set.
    $reachablePaths = static function (array $roots, array $graph): array {
        $found = [];
        $queue = [];
        foreach ($roots as $r) {
            if (!isset($found[$r])) {
                $found[$r] = $r;
                $queue[] = [$r, $r];
            }
        }
        while ($queue !== []) {
            [$current, $path] = \array_shift($queue);
            foreach ($graph[$current]['devDeps'] ?? [] as $depSlug => $_c) {
                if (isset($found[$depSlug])) {
                    continue;
                }
                $childPath = $path . ' -> ' . $depSlug;
                $found[$depSlug] = $childPath;
                $queue[] = [$depSlug, $childPath];
            }
        }
        return $found;
    };

    $unusedFindings = 0;
    $report = '';

    foreach ($libData as $slug => $data) {
        $srcDir = \dirname($data['manifestPath']) . '/src';
        $testsDir = \dirname($data['manifestPath']) . '/tests';
        $directDeps = \array_keys($data['devDeps']);
        \sort($directDeps);
        $testRoots = \array_keys($data['testDeps']);
        $pathRepoTargets = $pathRepoTargetsOf($data['repos']);

        // Full set of siblings a fresh `composer install` (incl. dev) must
        // resolve: production requires + require-dev + the production closure of
        // both. Used for PRUNE_REPO_ONLY (a repo target absent here is dead).
        $neededSet = $reachablePaths(\array_merge($directDeps, $testRoots), $graph);

        $lines = [];

        // (1) Unused DIRECT (production) requires.
        foreach ($directDeps as $depSlug) {
            $prefixes = $psr4PrefixesOf($depSlug);
            if ($prefixes === []) {
                // No PSR-4 to grep for — cannot decide; surface for a human.
                $lines[] = \sprintf(
                    '  - %-23s sugarcraft/%s  (dep declares no autoload.psr-4 — cannot resolve namespace; classify by hand)',
                    'AMBIGUOUS_NO_PSR4',
                    $depSlug
                );
                $unusedFindings++;
                continue;
            }
            if ($namespaceHits($srcDir, $prefixes) !== []) {
                continue; // referenced in src/ — genuinely used.
            }
            // Would the dep still be pulled in if we dropped THIS direct require?
            // Reachable from every OTHER production require plus every require-dev
            // root. The lib's own edge to the dep is removed from the graph too —
            // otherwise a cycle back through this lib (e.g. candy-core ⇄ candy-pty)
            // would re-traverse the very edge we are hypothetically deleting and
            // falsely report the dep as still reachable. In-set ⇒ keep the repo
            // entry (only drop `require`); absent ⇒ the repo entry is safe to drop.
            $otherRoots = \array_merge(
                \array_values(\array_diff($directDeps, [$depSlug])),
                $testRoots
            );
            $prunedGraph = $graph;
            unset($prunedGraph[$slug]['devDeps'][$depSlug]);
            $reach = $reachablePaths($otherRoots, $prunedGraph);
            $keepRepo = isset($reach[$depSlug]);
            $class = $keepRepo ? 'PRUNE_REQUIRE_KEEP_REPO' : 'PRUNE_REQUIRE_AND_REPO';
            $testsUses = $namespaceHits($testsDir, $prefixes) !== [] ? 'yes' : 'no';
            if ($keepRepo) {
                $path = $reach[$depSlug] ?? '?';
                $viaTestDep = isset($data['testDeps'][\explode(' -> ', $path)[0]]);
                $note = 'transitive via ' . $path . ($viaTestDep ? ' [require-dev]' : '');
            } else {
                $note = 'not reachable via other requires or require-dev';
            }
            $lines[] = \sprintf(
                '  - %-23s sugarcraft/%s  tests_uses: %s  (%s)',
                $class,
                $depSlug,
                $testsUses,
                $note
            );
            $unusedFindings++;
        }

        // (2) Lingering repo-only entries: a path repo whose target is neither a
        // require, a require-dev, nor in the production closure of either.
        $repoOnly = [];
        foreach ($pathRepoTargets as $target => $_url) {
            if (!isset($neededSet[$target])) {
                $repoOnly[] = $target;
            }
        }
        \sort($repoOnly);
        foreach ($repoOnly as $target) {
            $lines[] = \sprintf(
                '  - %-23s ../%s  (repo entry: not a require/require-dev, not in either closure)',
                'PRUNE_REPO_ONLY',
                $target
            );
            $unusedFindings++;
        }

        if ($lines !== []) {
            $report .= $slug . ":\n" . \implode("\n", $lines) . "\n";
        }
    }

    \printf("check-path-repos --unused: scanned %d libs\n", \count($libData));
    if ($unusedFindings === 0) {
        \fwrite(\STDOUT, "check-path-repos --unused: no dead path-repo deps found\n");
        exit(0);
    }
    \fwrite(\STDOUT, "\nDead path-repo dependencies (candidates for pruning):\n\n");
    \fwrite(\STDOUT, $report);
    \fprintf(
        \STDOUT,
        "\n%d finding(s). Read-only report — prune by hand: remove the require AND its\n"
        . "repositories[] entry (PRUNE_REQUIRE_AND_REPO), just the require (KEEP_REPO), or\n"
        . "just the repo entry (PRUNE_REPO_ONLY). A `tests_uses: yes` means move the\n"
        . "require to require-dev rather than delete it. Re-run without --unused to\n"
        . "re-verify closure afterwards.\n",
        $unusedFindings
    );
    exit(1);
}

/**
 * Is `sugarcraft/<slug>` published on Packagist? Composer resolves a transitive
 * dep that lacks a local path-repo by falling back to Packagist, so a published
 * dep is NOT a broken closure even without a path-repo. Results are memoised for
 * the run; offline/--no-network treats unknowns as published (no false
 * positives — a genuinely unpublished lib is the only thing that breaks installs
 * and that case is the one we must never miss, so callers that want strictness
 * pass --strict-closure instead of relying on this probe).
 *
 * @param array<string, bool> $cache
 */
$isPublishedOnPackagist = static function (string $depSlug, bool $offline, array &$cache): bool {
    if ($offline) {
        return true; // assume published; --strict-closure overrides upstream.
    }
    if (isset($cache[$depSlug])) {
        return $cache[$depSlug];
    }
    $url = 'https://repo.packagist.org/p2/sugarcraft/' . $depSlug . '.json';
    $ctx = \stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $headers = @\get_headers($url, false, $ctx);
    if ($headers === false || $headers === []) {
        // Network failure — be conservative and assume published so we never
        // emit a false "broken" on a transient outage. --strict-closure is the
        // knob for "I want full closure regardless".
        return $cache[$depSlug] = true;
    }
    $status = (string) ($headers[0] ?? '');
    $published = \str_contains($status, ' 200');
    return $cache[$depSlug] = $published;
};

/** @var array<string, bool> $packagistCache */
$packagistCache = [];

// ---------------------------------------------------------------------------
// Pass 2 — for each lib, compute its transitive closure and assert every
// reachable sibling has a matching path-repo entry. A gap is reported only when
// the dep is genuinely unresolvable: no path-repo AND (in --strict-closure
// mode, always; otherwise only if it is not published on Packagist).
// ---------------------------------------------------------------------------

foreach ($libData as $slug => $data) {
    $libsScanned++;

    $closure = $transitiveDeps($slug, $libData);
    if ($closure === []) {
        continue;
    }

    /** @var array<int, array<string, mixed>>|array<string, array<string, mixed>> $repos */
    $repos = $data['repos'];

    // Handle both array form and object-keyed-by-name form.
    $reposArray = [];
    if ($repos === []) {
        $reposArray = [];
    } elseif (\array_keys($repos) === \range(0, \count($repos) - 1)) {
        // Sequential array — already correct form.
        $reposArray = $repos;
    } else {
        // Associative object keyed by name — extract the repo objects.
        foreach ($repos as $repo) {
            if (\is_array($repo)) {
                $reposArray[] = $repo;
            }
        }
    }

    $pathRepoTargets = [];
    foreach ($reposArray as $repo) {
        if (!\is_array($repo)) {
            continue;
        }
        if (($repo['type'] ?? null) !== 'path') {
            continue;
        }
        $url = (string) ($repo['url'] ?? '');
        if ($url === '') {
            continue;
        }
        // Strip "../" prefix; the trailing dir-name is the dep slug.
        $depSlug = \basename(\rtrim($url, '/'));
        $pathRepoTargets[$depSlug] = $url;
    }

    // Sort the closure for deterministic, dependency-order-stable output.
    \ksort($closure);

    $missingRepos = [];
    foreach ($closure as $depSlug => $introPath) {
        if (isset($pathRepoTargets[$depSlug])) {
            continue; // local path-repo present — resolvable.
        }
        // No path-repo. In default mode this is only a real break if the dep
        // can't fall back to Packagist either. --strict-closure flags it always.
        if (!$strictClosure && $isPublishedOnPackagist($depSlug, $noNetwork, $packagistCache)) {
            continue;
        }
        $missingRepos[] = $depSlug;
        if (!$fix) {
            $issues[] = "{$slug}: missing path-repo for {$depSlug} (required transitively via {$introPath})";
        }
    }

    if ($missingRepos === []) {
        continue;
    }

    if ($fix) {
        $fixRequests[$slug] = [
            'manifestPath' => $data['manifestPath'],
            'manifest' => $data['manifest'],
            'repos' => $repos,
            'missingRepos' => $missingRepos,
        ];
    }
}

// Apply all fixes after the scan is complete.
foreach ($fixRequests as $slug => $request) {
    $manifestPath = $request['manifestPath'];
    $manifest = $request['manifest'];
    $repos = $request['repos'];
    $missingRepos = $request['missingRepos'];

    // Normalise repos to array form.
    if ($repos === [] || $repos === null) {
        $manifest['repositories'] = [];
    } elseif (\array_keys($repos) !== \range(0, \count($repos) - 1)) {
        // Was an object — convert to array.
        $manifest['repositories'] = [];
        foreach ($repos as $repo) {
            if (\is_array($repo)) {
                $manifest['repositories'][] = $repo;
            }
        }
    }

    foreach ($missingRepos as $depSlug) {
        $manifest['repositories'][] = [
            'type' => 'path',
            'url' => '../' . $depSlug,
            'options' => ['symlink' => true],
        ];
        $fixedCount++;
    }

    $encoded = \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        \fwrite(\STDERR, "tools/check-path-repos.php: json_encode failed for {$slug}/composer.json\n");
        exit(2);
    }
    if (\file_put_contents($manifestPath, $encoded . "\n") === false) {
        \fwrite(\STDERR, "tools/check-path-repos.php: could not write {$slug}/composer.json\n");
        exit(2);
    }
}

\printf("check-path-repos: scanned %d libs\n", $libsScanned);

if ($issues !== []) {
    \fwrite(\STDERR, "\nPath-repo closure drift:\n");
    foreach ($issues as $issue) {
        \fwrite(\STDERR, "  - {$issue}\n");
    }
    \fwrite(\STDERR, "\nFix by adding a `{ \"type\": \"path\", \"url\": \"../<dep>\", \"options\": { \"symlink\": true } }` entry to repositories[].\n");
    if ($fix && $fixedCount > 0) {
        \fprintf(\STDERR, "\n%d path-repo entries inserted.\n", $fixedCount);
    }
    exit(1);
}

if ($fix) {
    \fprintf(\STDOUT, "check-path-repos: all %d issues fixed\n", $fixedCount);
} else {
    \fwrite(\STDOUT, "check-path-repos: closure clean\n");
}
exit(0);

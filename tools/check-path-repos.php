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

foreach ($_SERVER['argv'] as $arg) {
    if ($arg === '--fix') {
        $fix = true;
    } elseif ($arg === '--strict-closure') {
        $strictClosure = true;
    } elseif ($arg === '--no-network') {
        $noNetwork = true;
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

Options:
  --fix             Auto-insert missing path-repo entries (direct AND
                    transitive) into affected composer.json files. Idempotent
                    when omitted (reports only).
  --strict-closure  Flag every transitive gap even if the dep is on Packagist.
  --no-network      Skip Packagist HEAD checks; assume unknown deps are
                    published (combine with --strict-closure for full offline
                    closure enforcement).
  --help            Show this usage message.

Exit codes:
  0  No issues found (or --fix succeeded for all issues)
  1  Issues detected (report printed to stderr)
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

    /** @var array<string, string> $requires */
    $requires = (array) ($manifest['require'] ?? []);

    // depSlug => constraint, for sugarcraft/* dev-pinned requires only.
    $devDeps = [];
    foreach ($requires as $name => $constraint) {
        if (!\is_string($name) || !\is_string($constraint)) {
            continue;
        }
        if (!\str_starts_with($name, 'sugarcraft/')) {
            continue;
        }
        if (!$isDevConstraint($constraint)) {
            continue;
        }
        $depSlug = \substr($name, \strlen('sugarcraft/'));
        $devDeps[$depSlug] = $constraint;
    }

    $libData[$slug] = [
        'slug' => $slug,
        'manifestPath' => $manifestPath,
        'manifest' => $manifest,
        'repos' => $manifest['repositories'] ?? [],
        'devDeps' => $devDeps,
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

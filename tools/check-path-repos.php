<?php

declare(strict_types=1);

/**
 * Path-repo closure check for the SugarCraft monorepo.
 *
 * For every lib that declares a `"sugarcraft/<dep>": "@dev"` requirement
 * in its `composer.json`, this script verifies a corresponding path-repo
 * entry exists in `repositories[]` (type=path, url="../<dep>") so
 * `composer install` can resolve the symlink without falling back to
 * the VCS remote. Catches the CLAUDE.md gotcha:
 *
 *   > New transitive @dev deps need their path-repo added to every
 *   > consuming lib's repositories[].
 *
 * Exits 0 on clean closure, 1 with a printed report on any drift.
 *
 * With --fix: auto-inserts missing path-repo entries and exits 0 if every
 * issue was fixable. With --help: print usage and exit 0.
 *
 * Constraints other than `@dev` (e.g. `dev-master`, `^1.0`) are skipped
 * — those resolve via the VCS remote and don't need a path-repo. The
 * tool deliberately stays conservative: it complains only about the
 * one combination the maintainer is known to typo.
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

foreach ($_SERVER['argv'] as $arg) {
    if ($arg === '--fix') {
        $fix = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $help = true;
    }
}

if ($help) {
    \fwrite(\STDOUT, <<<'EOF'
Usage: php tools/check-path-repos.php [options]

Checks path-repo closure for the SugarCraft monorepo.

For every lib that declares a `sugarcraft/<dep>: @dev` requirement in its
composer.json, verify a corresponding path-repo entry exists in repositories[]:

    { "type": "path", "url": "../<dep>", "options": { "symlink": true } }

Options:
  --fix   Auto-insert missing path-repo entries into affected composer.json
          files. Without this flag the script is idempotent (reports only).
  --help  Show this usage message.

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

foreach ($libs as $manifestPath) {
    $slug = \basename(\dirname($manifestPath));
    // Skip vendor + bootstrap + docs scaffolds — they are not real libs.
    if (\in_array($slug, ['vendor', 'node_modules', 'docs', 'plans', 'tools', 'scripts'], true)) {
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

    $libsScanned++;

    /** @var array<string, string> $requires */
    $requires = (array) ($manifest['require'] ?? []);

    $atDevDeps = [];
    foreach ($requires as $name => $constraint) {
        if (!\is_string($name) || !\is_string($constraint)) {
            continue;
        }
        if (!\str_starts_with($name, 'sugarcraft/')) {
            continue;
        }
        if (\trim($constraint) !== '@dev') {
            continue;
        }
        $atDevDeps[$name] = $constraint;
    }

    if ($atDevDeps === []) {
        continue;
    }

    /** @var array<int, array<string, mixed>>|array<string, array<string, mixed>> $repos */
    $repos = $manifest['repositories'] ?? [];

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

    $missingRepos = [];
    foreach ($atDevDeps as $name => $_constraint) {
        $depSlug = \substr($name, \strlen('sugarcraft/'));
        if (!isset($pathRepoTargets[$depSlug])) {
            $missingRepos[] = $depSlug;
            if (!$fix) {
                $issues[] = "{$slug}: require[\"{$name}\"]=@dev but no path-repo entry for ../{$depSlug}";
            }
        }
    }

    if ($missingRepos === []) {
        continue;
    }

    if ($fix) {
        $fixRequests[$slug] = [
            'manifestPath' => $manifestPath,
            'manifest' => $manifest,
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

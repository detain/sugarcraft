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
 * Constraints other than `@dev` (e.g. `dev-master`, `^1.0`) are skipped
 * — those resolve via the VCS remote and don't need a path-repo. The
 * tool deliberately stays conservative: it complains only about the
 * one combination the maintainer is known to typo.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P4.5)
 */

$root = \realpath(__DIR__ . '/..');
if ($root === false) {
    \fwrite(\STDERR, "tools/check-path-repos.php: cannot resolve monorepo root\n");
    exit(2);
}

$libs = \glob($root . '/*/composer.json') ?: [];
$issues = [];
$libsScanned = 0;

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

    /** @var array<int, array<string, mixed>> $repos */
    $repos = (array) ($manifest['repositories'] ?? []);

    $pathRepoTargets = [];
    foreach ($repos as $repo) {
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

    foreach ($atDevDeps as $name => $_constraint) {
        $depSlug = \substr($name, \strlen('sugarcraft/'));
        if (!isset($pathRepoTargets[$depSlug])) {
            $issues[] = "{$slug}: require[\"{$name}\"]=@dev but no path-repo entry for ../{$depSlug}";
        }
    }
}

\fwrite(\STDOUT, "check-path-repos: scanned {$libsScanned} libs\n");

if ($issues !== []) {
    \fwrite(\STDERR, "\nPath-repo closure drift:\n");
    foreach ($issues as $issue) {
        \fwrite(\STDERR, "  - {$issue}\n");
    }
    \fwrite(\STDERR, "\nFix by adding a `{ \"type\": \"path\", \"url\": \"../<dep>\", \"options\": { \"symlink\": true } }` entry to repositories[].\n");
    exit(1);
}

\fwrite(\STDOUT, "check-path-repos: closure clean\n");
exit(0);

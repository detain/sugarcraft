<?php

declare(strict_types=1);

/**
 * Resolved-SHA sibling pinning for release builds (OD-14, lane G6-P of the
 * reviews-1103 program; ruling: review.md 6.1 OD-14 / OD-8 "pinned to the
 * tested tree's SHAs").
 *
 * WHY THIS SCRIPT EXISTS. A release workflow checks the monorepo out at one
 * explicit commit — the commit CI tested — and then `composer install` inside
 * the app fetched every `sugarcraft/*` sibling from the PACKAGIST stream
 * (dev-master of the split repos), i.e. whatever HEAD happened to be at the
 * moment of install, not the bytes that were tested. The @dev doctrine stays
 * documented in candy-code/docs/CI.md; this script closes the window for
 * release builds without touching a committed manifest:
 *
 *   inject — transitorily add path repositories for the FULL transitive
 *            sugarcraft/* require closure of the app, written with
 *            `"options": {"symlink": false}`. symlink:false is load-bearing,
 *            not cosmetic: build-phar.php's Guard 4 refuses to package any
 *            tree containing a symlink, and the monorepo CI doctrine of
 *            symlink:true path repos would trip it on every release build.
 *            Composer then resolves the siblings from THIS checkout's bytes —
 *            the tested tree — and the emitted sidecar records which SHA
 *            those bytes came from.
 *   verify — after install, read the app's composer.lock and prove every
 *            sugarcraft/* package resolved from a path repository — across
 *            BOTH the production (packages) and dev (packages-dev) sections:
 *            the release pipelines' smoke legs install with dev deps, so a
 *            floating dev sibling (e.g. sugar-testing off Packagist
 *            dev-master) would ship untested bytes through an otherwise
 *            green verify. A lock
 *            entry pointing at a network dist URL means the sibling floated
 *            back to Packagist (constraint unsatisfied by the local dir,
 *            renamed package, whatever the cause) and the release must abort.
 *            Fail-closed is the whole point: silent fallback would keep
 *            shipping floats while the sidecar claimed pins.
 *
 * POLICY. This script mutates only the runner's ephemeral checkout; the
 * committed tree keeps bare `@dev` requires and NO per-lib path repos, so
 * `php tools/check-path-repos.php --no-lib-path-repos` stays green on every
 * commit (the inverse of the closure check, same doctrine as CI's own scratch
 * injection in the path-repo-check job — that injection is even `git checkout
 * --`'d away for the same reason). It never writes inside vendor/.
 *
 * Exit codes: 0 ok · 1 verify found an unpinned (network-resolved) sibling ·
 * 2 fatal (unparseable manifest, missing sibling directory, bad usage).
 */

/** @return never */
function pin_usage(int $code): never
{
    fwrite(STDERR, <<<'EOF'
    Usage:
      php scripts/pin-siblings.php inject <app-dir> --root <monorepo-root> --sha <full-sha>
          [--out <pins.json>] [--ref <human-ref>]
      php scripts/pin-siblings.php verify <app-dir>

    inject   adds path repositories (symlink:false) for the transitive
             sugarcraft/* closure of <app-dir>/composer.json, in place, and
             writes the provenance sidecar (default <app-dir>/sibling-pins.json).
    verify   reads <app-dir>/composer.lock and fails (exit 1) if any
             sugarcraft/* package resolved from anything but a path dist.

    EOF);
    exit($code);
}

/**
 * The sibling package names an app consumes, transitively: every
 * `sugarcraft/*` require of the app plus every `sugarcraft/*` require of
 * those siblings, walking each sibling's own composer.json. Same graph, same
 * name→directory mapping (`sugarcraft/<pkg>` lives at `<root>/<pkg>`) that
 * tools/check-path-repos.php enforces the closure of for the root manifest.
 *
 * @param array<string,mixed> $manifest
 * @return list<string>
 */
function pin_sugarcraft_requires(array $manifest): array
{
    $names = [];
    foreach (['require', 'require-dev'] as $section) {
        /** @var array<string,mixed> $requires */
        $requires = $manifest[$section] ?? [];
        foreach ($requires as $name => $constraint) {
            if (is_string($name) && str_starts_with($name, 'sugarcraft/')) {
                $names[$name] = true;
            }
        }
    }

    return array_keys($names);
}

/** @return array<string,mixed> */
function pin_read_manifest(string $path, string $what): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "pin-siblings: cannot read {$what} manifest {$path}\n");
        exit(2);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "pin-siblings: {$what} manifest {$path} is not valid JSON\n");
        exit(2);
    }

    return $decoded;
}

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$args = array_slice($argv, 1);
$mode = array_shift($args);
if ($mode === '--help' || $mode === '-h' || $mode === null) {
    pin_usage(0);
}
if (!in_array($mode, ['inject', 'verify'], true)) {
    pin_usage(2);
}

$positionals = [];
$options = [];
$pending = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            $pending = null;
        } else {
            $pending = substr($arg, 2);
            $options[$pending] = null;
        }
        continue;
    }
    if ($pending !== null) {
        $options[$pending] = $arg;
        $pending = null;
        continue;
    }
    $positionals[] = $arg;
}

$root = $options['root'] ?? null;
$sha = $options['sha'] ?? null;

if ($mode === 'inject') {
    $appDir = $positionals[0] ?? null;
    if (!is_string($appDir) || !is_string($root) || !is_string($sha) || !preg_match('/^[0-9a-f]{40}$/', $sha)) {
        pin_usage(2);
    }
    $manifestPath = rtrim($appDir, '/') . '/composer.json';
    $manifest = pin_read_manifest($manifestPath, 'app');
    $appIdentity = is_string($manifest['name'] ?? null) ? $manifest['name'] : basename(rtrim($appDir, '/'));

    // Transitive closure, breadth-first, recording how each sibling was reached.
    $direct = pin_sugarcraft_requires($manifest);
    $closure = [];          // name => depth ('direct' | 'transitive')
    $constraints = [];      // name => the constraint string seen on the edge
    $queue = [];
    foreach ($direct as $name) {
        $queue[$name] = 'direct';
    }
    // direct requires keep their constraint strings for the sidecar
    $edgeConstraint = [];
    foreach (['require', 'require-dev'] as $section) {
        /** @var array<string,mixed> $requires */
        $requires = $manifest[$section] ?? [];
        foreach ($requires as $name => $constraint) {
            if (is_string($name) && isset($queue[$name]) && is_string($constraint)) {
                $edgeConstraint[$name] = $constraint;
            }
        }
    }

    $seen = [];
    while ($queue !== []) {
        $name = (string) array_key_first($queue);
        $via = $queue[$name];
        unset($queue[$name]);
        if (isset($seen[$name])) {
            if ($seen[$name] === 'transitive' && $via === 'direct') {
                $closure[$name] = 'direct';
            }
            continue;
        }
        $seen[$name] = $via;
        $closure[$name] = $via;

        $pkg = (string) explode('/', $name, 2)[1];
        $dir = rtrim($root, '/') . '/' . $pkg;
        if (!is_dir($dir)) {
            fwrite(STDERR, "pin-siblings: {$name} (required {$via}) has no directory {$dir} in the checkout — refusing to release a float\n");
            exit(2);
        }
        $sibling = pin_read_manifest($dir . '/composer.json', "sibling {$pkg}");
        foreach (pin_sugarcraft_requires($sibling) as $nested) {
            if (!isset($seen[$nested]) && !isset($queue[$nested])) {
                $queue[$nested] = 'transitive';
            }
        }
    }

    // Inject path repositories, preserving any entries already present.
    /** @var list<array<string,mixed>> $repos */
    $repos = $manifest['repositories'] ?? [];
    if (!is_array($repos)) {
        $repos = [];
    }
    $existingUrls = [];
    foreach ($repos as $repo) {
        if (($repo['type'] ?? '') === 'path' && isset($repo['url'])) {
            $existingUrls[basename(rtrim((string) $repo['url'], '/'))] = true;
        }
    }
    foreach (array_keys($closure) as $name) {
        $pkg = (string) explode('/', $name, 2)[1];
        if (isset($existingUrls[$pkg])) {
            continue;
        }
        $repos[] = ['type' => 'path', 'url' => '../' . $pkg, 'options' => ['symlink' => false]];
        $existingUrls[$pkg] = true;
    }
    $manifest['repositories'] = $repos;

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        fwrite(STDERR, "pin-siblings: cannot re-encode {$manifestPath}\n");
        exit(2);
    }
    if (file_put_contents($manifestPath, $encoded . "\n") === false) {
        fwrite(STDERR, "pin-siblings: cannot write {$manifestPath}\n");
        exit(2);
    }

    $pinned = [];
    foreach ($closure as $name => $via) {
        $pinned[$name] = [
            'via' => $via,
            'constraint' => $edgeConstraint[$name] ?? null,
            'source' => 'monorepo@' . $sha,
        ];
    }
    $sidecar = [
        'app' => $appIdentity,
        'built_at_sha' => $sha,
        'ref' => $options['ref'] ?? null,
        'generator' => 'scripts/pin-siblings.php',
        'doctrine' => 'sibling packages resolved from the tested monorepo checkout (path repos, symlink:false); the @dev constraint in the committed manifest documents intent, this record states bytes',
        'pinned' => $pinned,
    ];
    $out = $options['out'] ?? (rtrim($appDir, '/') . '/sibling-pins.json');
    $sidecarJson = json_encode($sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($sidecarJson === false || file_put_contents((string) $out, $sidecarJson . "\n") === false) {
        fwrite(STDERR, "pin-siblings: cannot write sidecar {$out}\n");
        exit(2);
    }

    printf("pin-siblings: injected %d sibling path-repos into %s; sidecar %s\n", count($closure), $manifestPath, (string) $out);
    exit(0);
}

// -- verify ----------------------------------------------------------------

$appDir = $positionals[0] ?? null;
if (!is_string($appDir)) {
    pin_usage(2);
}
$lockPath = rtrim($appDir, '/') . '/composer.lock';
$lock = pin_read_manifest($lockPath, 'lock');
// Scan BOTH lock sections: pin_sugarcraft_requires() walks require +
// require-dev, and the post-verify smoke legs run `composer install`
// WITH dev deps — the guard must cover exactly what those legs execute.
/** @var list<array<string,mixed>> $packages */
$packages = [];
foreach (['packages', 'packages-dev'] as $lockSection) {
    /** @var list<array<string,mixed>> $sectionPackages */
    $sectionPackages = $lock[$lockSection] ?? [];
    foreach ($sectionPackages as $package) {
        $packages[] = $package;
    }
}
$floats = [];
$checked = 0;
foreach ($packages as $package) {
    $name = (string) ($package['name'] ?? '');
    if (!str_starts_with($name, 'sugarcraft/')) {
        continue;
    }
    $checked++;
    $distUrl = (string) ($package['dist']['url'] ?? '');
    $distType = (string) ($package['dist']['type'] ?? '');
    if ($distType === 'path' || str_starts_with($distUrl, '../')) {
        continue;
    }
    $floats[] = $name . ' resolved from ' . ($distUrl !== '' ? $distUrl : 'type ' . ($distType !== '' ? $distType : 'source ' . (string) ($package['source']['url'] ?? 'unknown')));
}
printf("pin-siblings: verified %d sugarcraft/* packages (packages + packages-dev) in %s\n", $checked, $lockPath);
if ($floats !== []) {
    fwrite(STDERR, "pin-siblings: FLOATING siblings (not resolved from the tested tree):\n  - " . implode("\n  - ", $floats) . "\n");
    exit(1);
}
if ($checked === 0) {
    fwrite(STDERR, "pin-siblings: zero sugarcraft/* packages in the lock — an app with sibling requires that resolves NONE is a solver anomaly, refusing to call that green\n");
    exit(2);
}
exit(0);

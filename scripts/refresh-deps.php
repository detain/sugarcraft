<?php

declare(strict_types=1);

/**
 * Refresh third-party dependencies across the monorepo WITHOUT destroying the
 * sibling path-repo closure.
 *
 * WHY THIS EXISTS. The obvious way to "keep everything on the latest" — run
 * `composer update` in each lib — does the opposite for `sugarcraft/*` deps.
 * A bare update resolves siblings from PACKAGIST, which lags the working tree
 * by: local commit → push → sync-sugarcraft.yml splitsh → sugarcraft/<lib> →
 * Packagist index. Measured 2026-08-22: an update in `sugar-crush` replaced a
 * symlinked `candy-core` with a Packagist copy two full audit rounds old, and
 * the suite still passed — it was simply no longer testing the working tree.
 *
 * Siblings need no updating at all. `vendor/sugarcraft/candy-core` IS
 * `../candy-core` when the path repo is injected, so it is current the instant
 * a file is saved. Only THIRD-PARTY packages (phpunit, guzzle, react, aws-sdk)
 * actually go stale. So this script updates third-party deps while restoring
 * the symlinks, then proves the symlinks are back before it exits non-zero or
 * zero.
 *
 * WHY NOT JUST `check-path-repos.php --fix --strict-closure`. That is step one
 * here, but its --fix path injects only the `require` graph. A lib whose sole
 * sibling dep sits in `require-dev` gets NOTHING and stays on Packagist
 * silently. Measured: `candy-buffer` requires `sugarcraft/candy-core` in
 * require-dev, receives zero injected entries, and its assertion count moved
 * 1,621 → 1,661 once it finally tested the real dependency — same test files.
 * This script supplements that gap rather than patching the checker, because
 * the checker's committed-tree guard (--no-lib-path-repos) is a different job
 * and CI depends on --fix behaving exactly as it does.
 *
 * THE INJECTED MANIFESTS ARE SCRATCH. A `repositories[]` block in a published
 * lib manifest is a hard fatal for anyone cloning the split repo, so every
 * manifest this script edits is restored via `git checkout --` before it
 * returns, in a finally block, even on fatal. The root manifest is the ONE
 * exception: the monorepo root is what `../<lib>` resolves against, so its
 * repositories[] is legitimate and permanent, and its composer.lock is tracked
 * and SHOULD be committed.
 *
 * Usage:
 *   php scripts/refresh-deps.php                 # every lib, third-party refresh
 *   php scripts/refresh-deps.php --root          # ...and the root (updates tracked lock)
 *   php scripts/refresh-deps.php --libs=a,b      # only these
 *   php scripts/refresh-deps.php --verify-only   # prove the closure, change nothing
 *   php scripts/refresh-deps.php --dry-run       # print the plan
 *
 * Exit 0 only if every targeted lib ends with a fully symlinked closure.
 */

$root = \dirname(__DIR__);
\chdir($root);

$opts = ['root' => false, 'verify' => false, 'dry' => false, 'libs' => null];
foreach (\array_slice($_SERVER['argv'], 1) as $arg) {
    if ($arg === '--root') {
        $opts['root'] = true;
    } elseif ($arg === '--verify-only') {
        $opts['verify'] = true;
    } elseif ($arg === '--dry-run') {
        $opts['dry'] = true;
    } elseif (\str_starts_with($arg, '--libs=')) {
        $opts['libs'] = \array_filter(\explode(',', \substr($arg, 7)));
    } elseif ($arg === '--help' || $arg === '-h') {
        \fwrite(\STDOUT, "See the docblock at the top of this file.\n");
        exit(0);
    } else {
        \fwrite(\STDERR, "unknown option: {$arg}\n");
        exit(2);
    }
}

/** A dev constraint pins a moving HEAD inside the monorepo and needs a path repo. */
$isDevConstraint = static fn (string $c): bool =>
    $c === '@dev' || \str_starts_with($c, 'dev-') || \str_contains($c, '@dev');

$manifestOf = static function (string $lib) use ($root): ?array {
    $f = "{$root}/{$lib}/composer.json";
    if (!\is_file($f)) {
        return null;
    }
    $j = \json_decode((string) \file_get_contents($f), true);

    return \is_array($j) ? $j : null;
};

$siblingsIn = static function (array $section) use ($isDevConstraint): array {
    $out = [];
    foreach ($section as $name => $constraint) {
        if (\str_starts_with($name, 'sugarcraft/') && $isDevConstraint((string) $constraint)) {
            $out[] = \substr($name, \strlen('sugarcraft/'));
        }
    }

    return $out;
};

$allLibs = [];
foreach (\scandir($root) ?: [] as $e) {
    if ($e[0] === '.' || $e === 'vendor' || !\is_dir("{$root}/{$e}")) {
        continue;
    }
    if (\is_file("{$root}/{$e}/composer.json")) {
        $allLibs[] = $e;
    }
}
\sort($allLibs);

$targets = $opts['libs'] ?? $allLibs;
foreach ($targets as $t) {
    if (!\in_array($t, $allLibs, true)) {
        \fwrite(\STDERR, "not a lib: {$t}\n");
        exit(2);
    }
}

/**
 * Full closure for one lib: its `require` siblings, its `require-dev` siblings,
 * and the PRODUCTION closure of both. require-dev matters because a harness
 * like candy-testing appears only there, yet `composer update` (which installs
 * dev) still has to resolve it and its own production deps locally.
 */
$closureOf = static function (string $lib) use ($manifestOf, $siblingsIn): array {
    $m = $manifestOf($lib);
    if ($m === null) {
        return [];
    }
    $seed = \array_merge($siblingsIn($m['require'] ?? []), $siblingsIn($m['require-dev'] ?? []));
    $seen = [];
    $queue = $seed;
    while ($queue !== []) {
        $n = \array_shift($queue);
        if (isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $dm = $manifestOf($n);
        if ($dm === null) {
            continue;   // named but absent from this checkout — leave it to Packagist
        }
        foreach ($siblingsIn($dm['require'] ?? []) as $next) {
            $queue[] = $next;
        }
    }

    // A lib can reach ITSELF through a dependency's own requires (candy-core →
    // candy-pty → candy-core). Injecting `../<self>` would be circular.
    unset($seen[$lib]);

    return \array_keys($seen);
};

/** Every vendored sibling must be a symlink resolving INSIDE this monorepo. */
$verify = static function (string $lib) use ($root): array {
    $dir = "{$root}/{$lib}/vendor/sugarcraft";
    if (!\is_dir($dir)) {
        return ['n' => 0, 'links' => 0, 'escaping' => 0, 'stale' => []];
    }
    $n = $links = $escaping = 0;
    $stale = [];
    foreach (\glob("{$dir}/*") ?: [] as $p) {
        $n++;
        if (\is_link($p)) {
            $links++;
            $rp = \realpath($p);
            if ($rp === false || !\str_starts_with($rp . '/', $root . '/')) {
                $escaping++;
            }
        } else {
            $stale[] = \basename($p);
        }
    }

    return ['n' => $n, 'links' => $links, 'escaping' => $escaping, 'stale' => $stale];
};

$report = static function (array $libs) use ($verify): bool {
    $ok = true;
    foreach ($libs as $lib) {
        $v = $verify($lib);
        if ($v['n'] === 0) {
            \printf("  %-20s no vendor/sugarcraft (no sibling deps installed)\n", $lib);
            continue;
        }
        $bad = $v['links'] !== $v['n'] || $v['escaping'] > 0;
        $ok = $ok && !$bad;
        \printf(
            "  %-20s %2d/%-2d symlinks  escaping=%d %s%s\n",
            $lib,
            $v['links'],
            $v['n'],
            $v['escaping'],
            $bad ? '*** STALE ***' : 'ok',
            $v['stale'] !== [] ? '  packagist: ' . \implode(',', \array_slice($v['stale'], 0, 6)) : ''
        );
    }

    return $ok;
};

if ($opts['verify']) {
    \fwrite(\STDOUT, "Closure verification (no changes):\n");
    exit($report($targets) ? 0 : 1);
}

if ($opts['dry']) {
    \fwrite(\STDOUT, "Would refresh " . \count($targets) . " libs:\n");
    foreach ($targets as $lib) {
        \printf("  %-20s closure: %s\n", $lib, \implode(' ', $closureOf($lib)) ?: '(none)');
    }
    if ($opts['root']) {
        \fwrite(\STDOUT, "  + root `composer update -v -o -W` (tracked lock, KEPT)\n");
    }
    exit(0);
}

$restore = static function () use ($root): void {
    // Scratch manifests, always discarded — a repositories[] block in a
    // published lib manifest is a hard fatal in a split-repo clone.
    \exec('git -C ' . \escapeshellarg($root) . " checkout -- '*/composer.json' 2>/dev/null");
};

$failed = [];
try {
    \fwrite(\STDOUT, "Injecting path-repo closure (scratch)...\n");
    \exec('php ' . \escapeshellarg("{$root}/tools/check-path-repos.php") . ' --fix --strict-closure 2>&1', $o, $rc);
    \fwrite(\STDOUT, '  ' . (\end($o) ?: '') . "\n");

    // Supplement the require-dev gap --fix leaves behind.
    $supplemented = [];
    foreach ($targets as $lib) {
        $m = $manifestOf($lib);
        if ($m === null) {
            continue;
        }
        $have = [];
        foreach ($m['repositories'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'path' && isset($r['url'])) {
                $have[\basename((string) $r['url'])] = true;
            }
        }
        $missing = \array_values(\array_filter(
            $closureOf($lib),
            static fn (string $d): bool => !isset($have[$d]) && \is_dir("{$root}/{$d}")
        ));
        if ($missing === []) {
            continue;
        }
        $m['repositories'] = $m['repositories'] ?? [];
        foreach ($missing as $d) {
            $m['repositories'][] = ['type' => 'path', 'url' => "../{$d}", 'options' => ['symlink' => true]];
        }
        \file_put_contents(
            "{$root}/{$lib}/composer.json",
            \json_encode($m, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
        );
        $supplemented[$lib] = $missing;
    }
    foreach ($supplemented as $lib => $missing) {
        \printf("  supplemented %-18s + %s  (require-dev gap)\n", $lib, \implode(',', $missing));
    }

    \fwrite(\STDOUT, "\nUpdating third-party deps (siblings resolve to symlinks)...\n");
    foreach ($targets as $i => $lib) {
        \printf("  [%2d/%2d] %-20s ", $i + 1, \count($targets), $lib);
        $out = [];
        \exec(
            'cd ' . \escapeshellarg("{$root}/{$lib}") . ' && composer update -o -W --no-interaction 2>&1',
            $out,
            $rc
        );
        if ($rc !== 0) {
            $failed[] = $lib;
            \fwrite(\STDOUT, "FAILED rc={$rc}\n");
            foreach (\array_slice($out, -6) as $l) {
                \fwrite(\STDOUT, "        {$l}\n");
            }
        } else {
            \fwrite(\STDOUT, "ok\n");
        }
    }
} finally {
    \fwrite(\STDOUT, "\nDiscarding scratch manifests...\n");
    $restore();
}

if ($opts['root']) {
    // The root is the directory ../<lib> resolves against, so its
    // repositories[] is legitimate and its composer.lock is TRACKED. Do not
    // revert either — the changed lock is the deliverable.
    \fwrite(\STDOUT, "\nUpdating root (tracked composer.lock is KEPT)...\n");
    \exec('cd ' . \escapeshellarg($root) . ' && composer update -v -o -W --no-interaction 2>&1', $ro, $rrc);
    \fwrite(\STDOUT, '  root rc=' . $rrc . "\n");
    if ($rrc !== 0) {
        foreach (\array_slice($ro, -8) as $l) {
            \fwrite(\STDOUT, "    {$l}\n");
        }
        $failed[] = '(root)';
    }
}

\fwrite(\STDOUT, "\nVerifying the closure survived:\n");
$closureOk = $report($targets);

\exec('php ' . \escapeshellarg("{$root}/tools/check-path-repos.php") . ' --no-lib-path-repos 2>&1', $g, $grc);
\fwrite(\STDOUT, "\ncommitted-tree guard (--no-lib-path-repos): rc={$grc}\n");
\exec('git -C ' . \escapeshellarg($root) . " ls-files '*/composer.lock'", $locks);
\fwrite(\STDOUT, 'tracked per-lib composer.lock: ' . \count($locks) . " (must be 0)\n");

$ok = $closureOk && $failed === [] && $grc === 0 && $locks === [];
\fwrite(\STDOUT, $ok ? "\nOK\n" : "\nPROBLEMS: " . \implode(', ', $failed ?: ['see above']) . "\n");
exit($ok ? 0 : 1);

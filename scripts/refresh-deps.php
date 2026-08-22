<?php

declare(strict_types=1);

/**
 * Refresh dependencies across the monorepo, in one of two DELIBERATE modes.
 *
 * THE TWO MODES ARE MUTUALLY EXCLUSIVE IN `vendor/`, AND THEY ANSWER DIFFERENT
 * QUESTIONS. Both are legitimate; picking the wrong one silently changes what
 * a test run means.
 *
 *   --mode=published (DEFAULT). Plain `composer update` per lib. Every
 *     `sugarcraft/*` sibling resolves from PACKAGIST at `dev-master`. This is
 *     exactly what an outside user gets when they `composer require
 *     sugarcraft/candy-shine`, and these libs ARE meant to be installed
 *     standalone by people who will never clone the monorepo. Running the
 *     suites in this state verifies the PUBLISHED ARTIFACT — that the split
 *     repo's manifest resolves, that the closure is satisfiable from Packagist
 *     alone, that we did not ship a lib depending on something unpublished.
 *     ⚠️ It reflects the last PUSHED-AND-INDEXED master, not the working tree.
 *
 *   --mode=linked. Inject the path-repo closure, update, then discard the
 *     scratch manifests, leaving `vendor/sugarcraft/*` as symlinks into the
 *     monorepo. This is the DEVELOPMENT state and what CI does before every
 *     install: it is the only way an edit to `candy-core/src/Util/Width.php`
 *     shows up in `candy-shine`'s test run, and the only reason a PR that
 *     breaks a sibling fails the dependent lib's job.
 *
 * WHY THE MODE MUST BE EXPLICIT. Measured 2026-08-22: a `composer update` in
 * `sugar-crush` replaced a symlinked `candy-core` with a Packagist copy two
 * audit rounds old — because master had 22 UNPUSHED commits, so Packagist
 * could not have had them. The suite still passed and still printed a total;
 * it had simply stopped testing the working tree. That is not an argument
 * against published mode. It is an argument that published mode is only
 * meaningful AFTER a push has been synced and indexed, and that a suite figure
 * is uninterpretable unless you know which mode produced it. Published mode
 * therefore refuses to run with unpushed commits unless forced.
 *
 * WHAT NEVER CHANGES. Per-lib manifests reference siblings as `dev-master` and
 * carry NO `repositories[]` block: a path url is a hard fatal for anyone
 * cloning the split repo. Linked mode's injected manifests are scratch and are
 * restored via `git checkout --` in a `finally`, even on fatal. The root
 * manifest is the ONE exception — the monorepo root is what `../<lib>`
 * resolves against, so its repositories[] is permanent and its composer.lock
 * is tracked and SHOULD be committed.
 *
 * WHY LINKED MODE COMPUTES ITS OWN CLOSURE. `check-path-repos.php --fix
 * --strict-closure` injects only the `require` graph. Verified: it injects
 * ZERO entries for `candy-buffer`, whose only sibling dep sits in
 * `require-dev`; that lib's assertion count moved 1,621 -> 1,661 once it
 * finally tested the real dependency. The checker is left alone because CI
 * depends on its current behaviour.
 *
 * Usage:
 *   php scripts/refresh-deps.php                    # published, every lib
 *   php scripts/refresh-deps.php --mode=linked      # development wiring
 *   php scripts/refresh-deps.php --root             # ...also the root
 *   php scripts/refresh-deps.php --libs=a,b         # only these
 *   php scripts/refresh-deps.php --status           # which mode is each lib in?
 *   php scripts/refresh-deps.php --dry-run
 *
 * Exit 0 only if every targeted lib ends in the requested state.
 */

$root = \dirname(__DIR__);
\chdir($root);

$opts = ['root' => false, 'verify' => false, 'dry' => false, 'libs' => null,
    'mode' => 'published', 'force' => false];
foreach (\array_slice($_SERVER['argv'], 1) as $arg) {
    if ($arg === '--root') {
        $opts['root'] = true;
    } elseif ($arg === '--verify-only' || $arg === '--status') {
        $opts['verify'] = true;
    } elseif ($arg === '--mode=published' || $arg === '--mode=linked') {
        $opts['mode'] = \substr($arg, 7);
    } elseif ($arg === '--force') {
        $opts['force'] = true;
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

/**
 * A lib is consistent when every vendored sibling is in the SAME state. A
 * mixture is the dangerous case: it reads as working while some deps are the
 * working tree and others are a Packagist snapshot of unknown age.
 */
$report = static function (array $libs, string $want) use ($verify): bool {
    $ok = true;
    $tally = ['linked' => 0, 'published' => 0, 'mixed' => 0];
    foreach ($libs as $lib) {
        $v = $verify($lib);
        if ($v['n'] === 0) {
            \printf("  %-20s -            no vendor/sugarcraft\n", $lib);
            continue;
        }
        $state = $v['links'] === $v['n'] ? 'linked' : ($v['links'] === 0 ? 'published' : 'mixed');
        $tally[$state]++;
        $bad = $state !== $want || $v['escaping'] > 0;
        $ok = $ok && !$bad;
        \printf(
            "  %-20s %-10s %2d/%-2d symlinked  escaping=%d  %s%s\n",
            $lib,
            $state,
            $v['links'],
            $v['n'],
            $v['escaping'],
            $bad ? '*** WANTED ' . \strtoupper($want) . ' ***' : 'ok',
            $state === 'mixed' ? '  packagist: ' . \implode(',', \array_slice($v['stale'], 0, 6)) : ''
        );
    }
    \printf(
        "  --\n  linked=%d  published=%d  mixed=%d\n",
        $tally['linked'],
        $tally['published'],
        $tally['mixed']
    );

    return $ok;
};

if ($opts['verify']) {
    \fwrite(\STDOUT, "Vendor state per lib (no changes; wanted={$opts['mode']}):\n");
    exit($report($targets, $opts['mode']) ? 0 : 1);
}

if ($opts['dry']) {
    \fwrite(\STDOUT, "Would refresh " . \count($targets) . " libs in {$opts['mode']} mode:\n");
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

/**
 * PUBLISHED-MODE POST-CONDITION. If the tree is clean and fully pushed, then
 * what Packagist serves for `dev-master` MUST be byte-identical to the working
 * tree — that is the whole point of the exercise. Any drift means the pipeline
 * has not caught up: sync-sugarcraft.yml still running, the Packagist webhook
 * not yet fired, or the split for that lib skipped because affected-libs did
 * not consider it changed. Without this check a published-mode refresh looks
 * successful while installing last week's code, which is the exact failure
 * that voided two suite runs on 2026-08-22.
 */
$srcFingerprint = static function (string $dir): ?string {
    if (!\is_dir($dir)) {
        return null;
    }
    $files = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[\substr($f->getPathname(), \strlen($dir))] = \md5_file($f->getPathname());
        }
    }
    \ksort($files);

    return \md5(\serialize($files));
};

$publishedDrift = static function (array $libs) use ($root, $srcFingerprint): array {
    $checked = 0;
    $drift = [];
    foreach ($libs as $lib) {
        foreach (\glob("{$root}/{$lib}/vendor/sugarcraft/*") ?: [] as $p) {
            if (\is_link($p)) {
                continue;   // linked: current by construction, nothing to compare
            }
            $dep = \basename($p);
            $live = $srcFingerprint("{$root}/{$dep}/src");
            $got = $srcFingerprint("{$p}/src");
            if ($live === null || $got === null) {
                continue;
            }
            $checked++;
            if ($live !== $got) {
                $drift[$dep] = ($drift[$dep] ?? 0) + 1;
            }
        }
    }

    return [$checked, $drift];
};

/**
 * Published mode resolves siblings from Packagist, which can only ever serve
 * what has been PUSHED and INDEXED. With commits sitting unpushed, an update
 * silently installs older code than the tree it is run in — measured this way
 * once already, costing two void suite runs.
 */
if ($opts['mode'] === 'published') {
    \exec('git -C ' . \escapeshellarg($root) . ' log --oneline @{u}..HEAD 2>/dev/null', $ahead, $arc);
    $unpushed = $arc === 0 ? \count($ahead) : -1;
    if ($unpushed > 0) {
        \fwrite(\STDERR, "REFUSING: {$unpushed} unpushed commit(s) on this branch.\n");
        \fwrite(
            \STDERR,
            "Packagist cannot serve them, so a published-mode update would install\n"
            . "OLDER siblings than this working tree. Push first, let sync-sugarcraft.yml\n"
            . "and the Packagist webhook run, then re-run. Use --mode=linked to wire the\n"
            . "working tree instead, or --force to proceed anyway.\n"
        );
        if (!$opts['force']) {
            exit(3);
        }
        \fwrite(\STDERR, "--force given; proceeding against an out-of-date Packagist.\n");
    } elseif ($unpushed < 0) {
        \fwrite(\STDOUT, "note: no upstream tracking branch; cannot check for unpushed commits.\n");
    }
}

$failed = [];
$updateLibs = static function (array $targets, string $root, array &$failed): void {
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
};

if ($opts['mode'] === 'published' && \in_array('sugar-crush', $targets, true)) {
    // sugar-crush's suite carries the closure canary:
    // GitignoreAwarenessTest::testTheMonorepoPathRepoSymlinksAreNotFollowed
    // SKIPS when there are no path-repo symlinks to walk. Published mode
    // therefore moves its skip count 1 -> 2, which is precisely the alarm the
    // audit's suite floor uses to detect a destroyed closure. The figure is
    // not wrong, but it is no longer comparable to any recorded floor.
    \fwrite(
        \STDOUT,
        "WARNING: sugar-crush in published mode moves its skip count 1 -> 2\n"
        . "         (GitignoreAwarenessTest has no symlinks to walk). Its suite total is\n"
        . "         then NOT comparable to the recorded audit floor. Re-link it with\n"
        . "         --mode=linked --libs=sugar-crush before measuring.\n\n"
    );
}

if ($opts['mode'] === 'published') {
    // No injection: the committed manifests already say `dev-master` with no
    // repositories[], which is precisely what an outside consumer installs.
    \fwrite(\STDOUT, "Updating from Packagist (siblings = published dev-master)...\n");
    $updateLibs($targets, $root, $failed);
} else {
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

    \fwrite(\STDOUT, "\nUpdating (third-party moves; siblings resolve to symlinks)...\n");
    $updateLibs($targets, $root, $failed);
} finally {
    \fwrite(\STDOUT, "\nDiscarding scratch manifests...\n");
    $restore();
}
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

\fwrite(\STDOUT, "\nVerifying every lib ended in {$opts['mode']} state:\n");
$closureOk = $report($targets, $opts['mode']);

$driftOk = true;
if ($opts['mode'] === 'published') {
    \exec('git -C ' . \escapeshellarg($root) . ' status --porcelain', $dirty);
    [$checked, $drift] = $publishedDrift($targets);
    \fwrite(\STDOUT, "\nPackagist freshness ({$checked} vendored siblings compared to the working tree):\n");
    if ($drift === []) {
        \fwrite(\STDOUT, "  no drift — Packagist is serving this tree\n");
    } else {
        \arsort($drift);
        foreach ($drift as $dep => $n) {
            \printf("  %-20s DIFFERS from ../%s  (in %d lib%s)\n", $dep, $dep, $n, $n === 1 ? '' : 's');
        }
        \fwrite(
            \STDOUT,
            $dirty !== []
                ? "  NOTE: working tree has uncommitted changes; some drift is expected.\n"
                : "  Tree is CLEAN, so this drift means the publish pipeline has not caught up:\n"
                  . "  sync-sugarcraft.yml still running, Packagist webhook not yet indexed, or that\n"
                  . "  lib was not in the affected set. Re-run later; do NOT trust suite figures taken\n"
                  . "  now as reflecting this tree.\n"
        );
        $driftOk = $dirty !== [];
    }
}

\exec('php ' . \escapeshellarg("{$root}/tools/check-path-repos.php") . ' --no-lib-path-repos 2>&1', $g, $grc);
\fwrite(\STDOUT, "\ncommitted-tree guard (--no-lib-path-repos): rc={$grc}\n");
\exec('git -C ' . \escapeshellarg($root) . " ls-files '*/composer.lock'", $locks);
\fwrite(\STDOUT, 'tracked per-lib composer.lock: ' . \count($locks) . " (must be 0)\n");

$ok = $closureOk && $driftOk && $failed === [] && $grc === 0 && $locks === [];
\fwrite(\STDOUT, $ok ? "\nOK\n" : "\nPROBLEMS: " . \implode(', ', $failed ?: ['see above']) . "\n");
exit($ok ? 0 : 1);

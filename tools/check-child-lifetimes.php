<?php

declare(strict_types=1);

/**
 * Child-lifetime accounting across every library in the monorepo.
 *
 * WHY THIS SCRIPT EXISTS (E486, closing E448). The canonical instrument for
 * this question is `sugar-crush/tests/Support/ChildLifetimeScanner.php`, and
 * the canonical gate that runs it is sugar-crush's
 * `DescriptorInheritanceGuardTest`. Both were built to answer "what does a
 * sugar-crush child inherit", so both read through `vendor/sugarcraft` — the
 * eighteen libraries sugar-crush actually requires. That reach is correct for
 * the question it answers and it is NOT the whole tree: E448 measured three
 * long-lived exposed spawns (`sugar-dash` ExternalModule, `sugar-reel`
 * AudioPlayer and FfmpegDecoder) sitting in libraries no sugar-crush test can
 * see, where a new one could appear, move, or multiply with nothing saying
 * so. The filed options were to promote the scanner into `candy-testing` or
 * copy the guard into each lib; E486 measured both as weaker — promotion
 * drags a test-support namespace into a published runtime package and the
 * copies multiply. What reaches 57 of 58 libraries without touching a single
 * `composer.json` is a `tools/` script and a step in the `path-repo-check`
 * job, because that job already runs from the repo root with the whole tree
 * checked out and needs no autoloader at all.
 *
 * WHY IT REQUIRES THE SCANNER INSTEAD OF COPYING IT. `tools/tests/` already
 * keeps a pinned copy of a different scanner (`StackedDocCommentTest`), and
 * that copy is a standing drift tax the workflow doc-block complains about.
 * Here there is no reason to pay it: this script and the guard live in the
 * SAME repository, so the canonical file is `require`d by path. The coupling
 * is bidirectional and loud — if the scanner file moves, this tool fatals
 * (a red nobody mistakes for flake), and if its findings change shape, the
 * integration test in `tools/tests/ChildLifetimesToolTest.php` reddens the
 * `tools/ guards` job on the next run.
 *
 * THE SAME COUPLING RUNS ONE LEVEL DEEPER, and this is the sentence that
 * keeps it from being rediscovered as a fatal: the scanner is loaded with NO
 * autoloader registered, so every file its class graph needs is required by
 * path below, in dependency order — `TokenFunctionRanges`, and since E174's
 * trait extraction `SplitsTopLevelArgumentsTrait`. A future `use` of another
 * Support trait inside the scanner (or one that trait itself grows) must
 * join that require list the same day it lands, or this tool dies at
 * class-declaration time on both the CLI path and the tools/tests gate.
 *
 * WHAT IS IN SCOPE. Every first-level directory that carries a
 * `composer.json` with an `autoload` section — a lib's REAL autoload roots
 * (psr-4, classmap, files), read from its manifest exactly the way
 * sugar-crush's guard reads them, never an assumed `src/`. `autoload-dev` is
 * deliberately not read for the same stated reason the guard gives: Composer
 * registers it for the root package only, so a lib's own tests are
 * unreachables for inheritance purposes and scanning them would only measure
 * fixtures. `sugar-crush` itself is excluded: its guard owns those sites,
 * holds local fix authority over them, and a second verdict on the same code
 * is how two rosters drift apart.
 *
 * WHAT COUNTS AS A FINDING. Three kinds, all from the scanner's own output,
 * none invented here: an EXPOSED site (`long` lifetime, nothing above fd 2
 * named in the spec — a parent descriptor at 3+ walks into a child that
 * outlives the spawning function), an UNCLASSIFIED site (the scanner could
 * not prove the child is reaped, which is a question, not a clean bill), and
 * an UNRESOLVED appearance of the name `proc_open` (method call, static call,
 * string literal, bare reference — the rule-14 half: droppable-looking
 * occurrences are reported, not skipped). Every finding needs a row in
 * ACCOUNTED below; an unknown finding reds with its exact key printed so the
 * fix is paste-and-judge, and a row whose site has disappeared or changed
 * count reds the other way. Unaccounted findings exit 1.
 *
 * WHAT A ROW MEANS, per kind (rule 7 — this is the judgement record, not an
 * exemption list): for the two `candy-pty` rows and the `candy-core` row, the
 * same REPORT sugar-crush's `ACCOUNTED_FOR_IN_LIBS` files — the fix lives in
 * a package this repo's CI does not own, so the row buys visibility, not
 * silence. For the three `sugar-dash`/`sugar-reel` rows, E366 shipped a
 * bounded TERM→KILL reap ladder at each site's teardown in this same round,
 * which closes the orphan half; the fd≥3 inheritance half REMAINS and this
 * row is where that stays written down. A row is allowed to say "exposed and
 * reported"; it is not allowed to say nothing.
 *
 * Usage:
 *     php tools/check-child-lifetimes.php [--root=<dir>] [--list]
 *
 * Exit codes:
 *     0  every derived finding has a matching row, every row still derives
 *     1  unaccounted finding, stale row, or count drift
 *     2  fatal (bad --root, unreadable manifest)
 */

use SugarCraft\Crush\Tests\Support\ChildLifetimeScanner;

require_once __DIR__ . '/../sugar-crush/tests/Support/TokenFunctionRanges.php';
// Required BEFORE the scanner: its class body declares `use
// SplitsTopLevelArgumentsTrait` (E174), same namespace, no import — with no
// autoloader here, the trait must already be declared or the scanner file
// fatals at declaration time. Keep this list in sync with the scanner's
// trait graph; see the class doc-block above for why.
require_once __DIR__ . '/../sugar-crush/tests/Support/SplitsTopLevelArgumentsTrait.php';
require_once __DIR__ . '/../sugar-crush/tests/Support/ChildLifetimeScanner.php';

final class CheckChildLifetimes
{
    /**
     * Every finding this tool derives from the tree today, with its judgement.
     *
     * Key: `<path relative to repo root>::<function the scanner names>`.
     * Count drift in either direction is red — see the class doc-block.
     *
     * @var array<string, array{count: int, reason: string}>
     */
    public const ACCOUNTED = [
        'candy-core/src/WorkerPool.php::spawnWorker' => [
            'count' => 1,
            'reason' => 'pool worker held in $this->workers and drained from the ReactPHP loop, '
                . 'so it outlives spawnWorker() by design; the scanner reads it as UNCLASSIFIED '
                . 'because the handle goes to is_resource() first. Spec is 0,1,2 only. Same site '
                . 'sugar-crush\'s ACCOUNTED_FOR_IN_LIBS reports — candy-core owns the fix.',
        ],
        'candy-pty/src/Spawn.php::proc' => [
            'count' => 1,
            'reason' => 'the PTY child, whose three stdio descriptors are all the one open slave '
                . 'stream; the handle is kept for the life of the pty, so silence above fd 2 is '
                . 'the shape of the library, not an oversight in it. Report row — NOT FIXABLE '
                . 'FROM REPO GUARD; mirrors sugar-crush\'s ACCOUNTED_FOR_IN_LIBS.',
        ],
        'candy-pty/src/Posix/PosixProcess.php::spawn' => [
            'count' => 1,
            'reason' => 'the same shape one layer down: the spec names fd 0 as a file and routes '
                . '1 and 2 to pipes or the real STDOUT/STDERR — the author thought about '
                . 'descriptors and still says nothing about 3+. Report row — NOT FIXABLE FROM '
                . 'REPO GUARD; mirrors sugar-crush\'s ACCOUNTED_FOR_IN_LIBS.',
        ],
        'sugar-dash/src/Plugin/ExternalModule.php::startProcess' => [
            'count' => 1,
            'reason' => 'persistent third-party plugin daemon, pipes on 0,1,2. E366 shipped the '
                . 'bounded TERM→KILL reap ladder in __destruct() (this round), so the child '
                . 'cannot outlive the dashboard; the fd≥3 inheritance exposure stands and this '
                . 'row is where it stays written down for sugar-dash to own.',
        ],
        'sugar-reel/src/AudioPlayer.php::start' => [
            'count' => 1,
            'reason' => 'ffplay/mpv companion with all-/dev/null FILE sinks (no parent-side pipe '
                . 'at all — closing a reader-less stderr pipe SIGPIPEs the child). E366 shipped '
                . '__destruct + BoundedReaper, closing the orphan half; the fd≥3 exposure stands '
                . 'for sugar-reel to own.',
        ],
        'sugar-reel/src/Decode/FfmpegDecoder.php::open' => [
            'count' => 1,
            'reason' => 'ffmpeg decode child. close() now walks the BoundedReaper grace ladder '
                . 'before proc_close() and __destruct() calls close() (E366); the fd≥3 exposure '
                . 'stands for sugar-reel to own.',
        ],
    ];

    /**
     * Derive every finding from the tree under $root.
     *
     * @return array{
     *     libs: int,
     *     files: int,
     *     sites: int,
     *     findings: list<array{key: string, kind: string, line: int, detail: string}>,
     * }
     */
    public static function derive(string $root): array
    {
        $findings = [];
        $libs = 0;
        $files = 0;
        $sites = 0;

        foreach (self::libDirs($root) as $lib) {
            $libs++;
            $manifest = self::readManifest("$root/$lib/composer.json", $lib);

            foreach (self::autoloadRoots("$root/$lib", $manifest) as $absolute) {
                foreach (self::phpFiles($absolute) as $file) {
                    $files++;
                    $scan = ChildLifetimeScanner::scan((string) file_get_contents($file));
                    $relative = ltrim(substr($file, strlen($root)), '/');

                    foreach ($scan['sites'] as $site) {
                        $sites++;
                        $key = $relative . '::' . $site['function'];

                        if ($site['lifetime'] === ChildLifetimeScanner::LIFETIME_UNCLASSIFIED) {
                            $findings[] = [
                                'key' => $key,
                                'kind' => 'unclassified',
                                'line' => $site['line'],
                                'detail' => 'scanner could not prove the child is reaped: ' . $site['reason'],
                            ];

                            continue;
                        }

                        if (
                            $site['lifetime'] === ChildLifetimeScanner::LIFETIME_LONG
                            && $site['highFds'] === []
                        ) {
                            $findings[] = [
                                'key' => $key,
                                'kind' => 'exposed',
                                'line' => $site['line'],
                                'detail' => 'long-lived child, spec names only fds ['
                                    . implode(',', $site['fds']) . ']; parent descriptors at 3+ inherit',
                            ];
                        }
                    }

                    foreach ($scan['unresolved'] as $appearance) {
                        $findings[] = [
                            'key' => $relative . '::' . $appearance['function'],
                            'kind' => 'unresolved:' . $appearance['kind'],
                            'line' => $appearance['line'],
                            'detail' => 'appearance of the name proc_open the scanner cannot follow',
                        ];
                    }
                }
            }
        }

        return ['libs' => $libs, 'files' => $files, 'sites' => $sites, 'findings' => $findings];
    }

    /**
     * Judge derived findings against ACCOUNTED.
     *
     * @param  list<array{key: string, kind: string, line: int, detail: string}> $findings
     * @return list<string> empty means green
     */
    public static function verdict(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding['key']] = ($counts[$finding['key']] ?? 0) + 1;
        }

        $problems = [];

        foreach ($findings as $finding) {
            if (!isset(self::ACCOUNTED[$finding['key']])) {
                $problems[] = sprintf(
                    'UNACCOUNTED %s at %s:%d — %s',
                    $finding['kind'],
                    $finding['key'],
                    $finding['line'],
                    $finding['detail'],
                );
            }
        }

        foreach (self::ACCOUNTED as $key => $row) {
            $derived = $counts[$key] ?? 0;

            if ($derived === 0) {
                $problems[] = "STALE ROW: nothing derives from {$key} any more — "
                    . 'the site was fixed, moved, or renamed; delete the row if so.';

                continue;
            }

            if ($derived !== $row['count']) {
                $problems[] = "COUNT DRIFT at {$key}: roster says {$row['count']}, the tree derives {$derived}.";
            }
        }

        return $problems;
    }

    /**
     * First-level directories that are libraries: a composer.json with an
     * autoload section. sugar-crush is skipped — its own guard owns it
     * (see the class doc-block) — and skipping it is stated, not defaulted.
     *
     * @return list<string> sorted
     */
    public static function libDirs(string $root): array
    {
        $libs = [];
        foreach ((array) scandir($root) as $entry) {
            if (!is_string($entry) || $entry === '' || $entry[0] === '.' || $entry === '..') {
                continue;
            }

            if (
                $entry === 'sugar-crush'
                || !is_dir("$root/$entry")
                || !is_file("$root/$entry/composer.json")
            ) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents("$root/$entry/composer.json"), true);
            if (is_array($manifest) && isset($manifest['autoload']) && is_array($manifest['autoload'])) {
                $libs[] = $entry;
            }
        }

        sort($libs);

        return $libs;
    }

    /**
     * The autoload roots one manifest declares — psr-4, classmap, files.
     *
     * @param  array<string, mixed> $manifest
     * @return list<string> absolute directories
     */
    public static function autoloadRoots(string $libDir, array $manifest): array
    {
        $autoload = is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
        $paths = [];

        foreach (['psr-4', 'classmap', 'files'] as $section) {
            foreach (is_array($autoload[$section] ?? null) ? $autoload[$section] : [] as $value) {
                foreach (is_array($value) ? $value : [$value] as $path) {
                    if (is_string($path) && $path !== '') {
                        $paths[] = rtrim($libDir . '/' . $path, '/');
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($paths, 'is_dir')));
    }

    /**
     * Every .php file under one root, deterministically ordered.
     *
     * @return list<string>
     */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ((array) scandir($current) as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current . '/' . $entry;
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (str_ends_with($entry, '.php')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readManifest(string $path, string $lib): array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "check-child-lifetimes: cannot read $lib/composer.json\n");
            exit(2);
        }

        return $decoded;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    $root = dirname(__DIR__);
    $list = in_array('--list', $argv, true);

    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, '--root=')) {
            $root = substr((string) $arg, 7);
        }
    }

    if (!is_dir($root)) {
        fwrite(STDERR, "check-child-lifetimes: --root is not a directory: $root\n");
        exit(2);
    }

    $report = CheckChildLifetimes::derive($root);
    $problems = CheckChildLifetimes::verdict($report['findings']);

    if ($list) {
        foreach ($report['findings'] as $finding) {
            printf("%-12s %s:%d\n", $finding['kind'], $finding['key'], $finding['line']);
        }
    }

    foreach ($problems as $problem) {
        fwrite(STDERR, "check-child-lifetimes: $problem\n");
    }

    printf(
        "check-child-lifetimes: %d libs, %d autoload-root files, %d proc_open sites, %d findings, %d accounted rows, %d problems\n",
        $report['libs'],
        $report['files'],
        $report['sites'],
        count($report['findings']),
        count(CheckChildLifetimes::ACCOUNTED),
        count($problems),
    );

    exit($problems === [] ? 0 : 1);
}

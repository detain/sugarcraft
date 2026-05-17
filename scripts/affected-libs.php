<?php
declare(strict_types=1);

/**
 * Compute the set of libs affected by a change-set and emit the per-job
 * matrices that .github/workflows/ci.yml consumes.
 *
 * Affected = directly-changed libs ∪ transitive closure of dependents (via
 * `require[sugarcraft/*]` only — `require-dev` is intentionally ignored so
 * a test-helper bump in candy-core does not retest every downstream).
 *
 * Why this exists: the ci.yml matrices were hand-maintained — adding a lib
 * meant editing two lockstep lists. This script auto-discovers libs from
 * the filesystem (every dir with composer.json + phpunit.xml) and prunes
 * to only the libs whose tests can actually be affected by the change.
 *
 * Usage:
 *   php scripts/affected-libs.php --all
 *   php scripts/affected-libs.php --range "$BEFORE..$AFTER"
 *   php scripts/affected-libs.php --files path/a path/b ...
 *   php scripts/affected-libs.php --files-stdin   # newline-separated on stdin
 *
 * Stdout: one JSON object (see KEYS below).
 * Stderr: human-readable debug summary.
 */

const ROOT = __DIR__ . '/..';

/** Per-job lib pools (hand-maintained — opt-in for OS-specific runners). */
const WINDOWS_LIBS = [
    'candy-core', 'sugar-prompt', 'sugar-bits', 'candy-shell', 'candy-shine',
];
const MACOS_LIBS = [
    // candy-pty itself is held out of the macOS pool until the
    // direct unit/integration tests are debugged on a real Darwin
    // box: tests 53-63 yield 6 errors + 1 failure, and the suite
    // hangs at test 64+ until GitHub's 30-min timeout. Coverage of
    // candy-pty's POSIX surface on macOS comes through its consumers
    // below (PosixBackend in candy-core, InProcessTransport in
    // candy-wish — both exercise the same posix_openpt + grantpt +
    // TIOCSWINSZ syscall chain). See pty-matrix.yml for a separate
    // macOS-targeted workflow once those tests are stable.
    'candy-core',
    'candy-wish',
];

/** PHP versions every lib's PHPUnit suite runs against. */
const PHP_VERSIONS = ['8.3', '8.4'];
const WINDOWS_PHP_VERSIONS = ['8.3', '8.4'];
const MACOS_PHP_VERSIONS = ['8.3'];

/** Coverage uses pcov on a single PHP version. */
const COVERAGE_PHP_VERSION = '8.3';

/**
 * Files that, if touched, force every lib into the matrix. These cross-cut
 * the whole monorepo — workflow / scripts / root composer changes can break
 * any lib, so we can't trust the dependency graph to scope them.
 */
const FORCE_ALL_FILES = [
    '.github/workflows/ci.yml',
    '.github/workflows/sync-sugarcraft.yml',
    '.github/workflows/vhs.yml',
    'scripts/affected-libs.php',
    'composer.json',
    'composer.lock',
];

function fail(string $msg): never
{
    fwrite(STDERR, "affected-libs: $msg\n");
    exit(2);
}

/** @return list<string> Sorted list of lib slugs discovered on disk. */
function discover_libs(): array
{
    $libs = [];
    foreach (glob(ROOT . '/*/composer.json') ?: [] as $path) {
        $dir = basename(dirname($path));
        // Skip anything that isn't a testable lib — phpunit.xml is the marker.
        if (is_file(dirname($path) . '/phpunit.xml')) {
            $libs[] = $dir;
        }
    }
    sort($libs);
    return $libs;
}

/**
 * Build forward-dep graph: lib → list of sibling sugarcraft libs it requires
 * in `require` (require-dev intentionally excluded).
 *
 * @param list<string> $libs
 * @return array<string, list<string>>
 */
function build_forward_graph(array $libs): array
{
    $graph = [];
    foreach ($libs as $lib) {
        $json = file_get_contents(ROOT . "/$lib/composer.json");
        if ($json === false) {
            fail("cannot read $lib/composer.json");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            fail("invalid JSON in $lib/composer.json");
        }
        $deps = [];
        foreach (($data['require'] ?? []) as $name => $_constraint) {
            if (is_string($name) && str_starts_with($name, 'sugarcraft/')) {
                $slug = substr($name, strlen('sugarcraft/'));
                if (in_array($slug, $libs, true)) {
                    $deps[] = $slug;
                }
            }
        }
        $graph[$lib] = $deps;
    }
    return $graph;
}

/**
 * Invert forward-deps into reverse-deps: lib → libs that require it.
 *
 * @param array<string, list<string>> $forward
 * @return array<string, list<string>>
 */
function invert(array $forward): array
{
    $rev = [];
    foreach (array_keys($forward) as $lib) {
        $rev[$lib] = [];
    }
    foreach ($forward as $lib => $deps) {
        foreach ($deps as $dep) {
            $rev[$dep][] = $lib;
        }
    }
    return $rev;
}

/**
 * Closure expansion: seed set ∪ everything reachable via the graph edges.
 *
 * @param array<string, list<string>> $graph
 * @param list<string> $seed
 * @return list<string>
 */
function closure(array $graph, array $seed): array
{
    $seen = array_fill_keys($seed, true);
    $queue = $seed;
    while ($queue) {
        $node = array_shift($queue);
        foreach ($graph[$node] ?? [] as $next) {
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[] = $next;
            }
        }
    }
    $out = array_keys($seen);
    sort($out);
    return $out;
}

/**
 * Map a changed file path → lib slug (or null if it doesn't live under a lib).
 *
 * @param list<string> $libs
 */
function file_to_lib(string $file, array $libs): ?string
{
    $slash = strpos($file, '/');
    if ($slash === false) {
        return null;
    }
    $head = substr($file, 0, $slash);
    return in_array($head, $libs, true) ? $head : null;
}

/** Decide whether the change-set is monorepo-wide. */
function should_force_all(array $changedFiles): bool
{
    foreach ($changedFiles as $f) {
        if (in_array($f, FORCE_ALL_FILES, true)) {
            return true;
        }
    }
    return false;
}

/**
 * GitHub Actions base-axes matrix. Cross-product is implicit; empty `lib`
 * collapses the whole matrix to 0 cells (no placeholder check_runs).
 *
 * @param list<string> $libs
 * @param list<string> $versions
 * @return array{php:list<string>, lib:list<string>}
 */
function php_lib_matrix(array $libs, array $versions): array
{
    // Don't broadcast the PHP axis when there are no libs — leaves both
    // axes empty so the matrix collapses regardless of which axis Actions
    // multiplies first.
    if ($libs === []) {
        return ['php' => [], 'lib' => []];
    }
    return ['php' => $versions, 'lib' => $libs];
}

// ---------------------------------------------------------------------------
// CLI plumbing
// ---------------------------------------------------------------------------

$argvIn = $argv;
array_shift($argvIn);

$mode = null;
$range = null;
$files = [];

while ($argvIn) {
    $a = array_shift($argvIn);
    switch ($a) {
        case '--all':
            $mode = 'all';
            break;
        case '--range':
            $mode = 'range';
            $range = array_shift($argvIn) ?? fail('--range needs an argument');
            break;
        case '--files':
            $mode = 'files';
            while ($argvIn && !str_starts_with($argvIn[0], '--')) {
                $files[] = array_shift($argvIn);
            }
            break;
        case '--files-stdin':
            $mode = 'files';
            $stdin = stream_get_contents(STDIN);
            if ($stdin !== false) {
                foreach (preg_split('/\r?\n/', $stdin) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $files[] = $line;
                    }
                }
            }
            break;
        default:
            fail("unknown argument: $a");
    }
}

if ($mode === null) {
    fail("one of --all | --range A..B | --files ... | --files-stdin is required");
}

$libs = discover_libs();
$forward = build_forward_graph($libs);
$reverse = invert($forward);

// ---------------------------------------------------------------------------
// Resolve the changed-files list and decide force-all vs scoped.
// ---------------------------------------------------------------------------

$forceAll = false;
$changedLibs = [];

if ($mode === 'all') {
    $forceAll = true;
} else {
    if ($mode === 'range') {
        $rangeArg = escapeshellarg((string) $range);
        $cmd = "git -C " . escapeshellarg(realpath(ROOT) ?: ROOT)
             . " diff --name-only $rangeArg";
        $out = [];
        $rc = 0;
        exec($cmd . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            // Unresolvable range (shallow clone, first commit on branch) →
            // safest behaviour is to test everything.
            fwrite(STDERR, "affected-libs: git diff failed for range '$range' — forcing all\n");
            $forceAll = true;
        } else {
            $files = array_values(array_filter($out, static fn ($l) => $l !== ''));
        }
    }

    if (!$forceAll) {
        $forceAll = should_force_all($files);
        if (!$forceAll) {
            $seen = [];
            foreach ($files as $f) {
                $lib = file_to_lib($f, $libs);
                if ($lib !== null && !isset($seen[$lib])) {
                    $seen[$lib] = true;
                    $changedLibs[] = $lib;
                }
            }
            sort($changedLibs);
        }
    }
}

// ---------------------------------------------------------------------------
// Compute affected = changedLibs + transitive dependents.
// ---------------------------------------------------------------------------

$affected = $forceAll ? $libs : closure($reverse, $changedLibs);

$affectedSet = array_fill_keys($affected, true);
$pickAffected = static fn (array $pool) =>
    array_values(array_filter($pool, static fn ($l) => isset($affectedSet[$l])));

$phpstanLibs = $pickAffected(array_values(array_filter(
    $libs,
    static fn ($l) => is_file(ROOT . "/$l/phpstan.neon")
)));

$windowsLibs = $pickAffected(WINDOWS_LIBS);
$macosLibs   = $pickAffected(MACOS_LIBS);

$output = [
    'all_libs'        => $libs,
    'force_all'       => $forceAll,
    'changed_libs'    => $changedLibs,
    'affected'        => $affected,
    'test_matrix'     => php_lib_matrix($affected,    PHP_VERSIONS),
    'coverage_libs'   => $affected,
    'coverage_php'    => COVERAGE_PHP_VERSION,
    'phpstan_matrix'  => php_lib_matrix($phpstanLibs, PHP_VERSIONS),
    'windows_matrix'  => php_lib_matrix($windowsLibs, WINDOWS_PHP_VERSIONS),
    'macos_matrix'    => php_lib_matrix($macosLibs,   MACOS_PHP_VERSIONS),
];

$cells = static fn (array $m) => count($m['lib']) * count($m['php']);

fwrite(STDERR, sprintf(
    "affected-libs: force_all=%s, changed=%d, affected=%d/%d, "
    . "test=%d, cov=%d, phpstan=%d, win=%d, mac=%d\n",
    $forceAll ? 'yes' : 'no',
    count($changedLibs),
    count($affected),
    count($libs),
    $cells($output['test_matrix']),
    count($output['coverage_libs']),
    $cells($output['phpstan_matrix']),
    $cells($output['windows_matrix']),
    $cells($output['macos_matrix']),
));

echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

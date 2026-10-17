<?php

declare(strict_types=1);

/**
 * check-lib-licenses.php — every split package must ship the root LICENSE verbatim.
 *
 * ISS-120 (sugar_issues.md): each package README links `](LICENSE)`, and the
 * split repos are minted by `.github/workflows/sync-sugarcraft.yml` with a
 * SINGLE `splitsh-lite --prefix="$lib"` per package (multi-prefix is a parse
 * error in the pinned splitsh-lite v1.0.1). A root-only LICENSE therefore
 * never reaches a split repo and every badge link is dead on arrival. The fix
 * is per-package LICENSE files — this guard keeps them there and identical.
 *
 * The package set is derived with the SAME rule the sync workflow uses —
 * `php scripts/affected-libs.php --all` → JSON `all_libs` — so the guard can
 * never drift from the split roster (one source of truth, no hardcoded count).
 *
 * Checks, per package:
 *   - `<pkg>/LICENSE` exists;
 *   - its bytes are identical to the root LICENSE (md5);
 *   - no top-level `<pkg>/*.md` links `](../LICENSE)` (an upward link splits
 *     into a dangling reference in the split repo).
 *
 * Usage (options are `=`-form only):
 *   php tools/check-lib-licenses.php              # check; exit 0/1
 *   php tools/check-lib-licenses.php --fix        # copy root LICENSE over missing/drifted
 *   php tools/check-lib-licenses.php --root=DIR   # run against another tree (tests)
 *   php tools/check-lib-licenses.php --list       # print the derived package set
 *   php tools/check-lib-licenses.php --help       # this text
 *
 * Exit codes:
 *   0  every package carries the root LICENSE and no upward links remain
 *   1  a package is missing/drifted, or a `](../LICENSE)` link was found
 *   2  the check could not run (bad --root, enumeration failure, unreadable
 *      bytes) — fail closed: a guard that cannot see the tree reports nothing
 *
 * Guarded by tools/tests/CheckLibLicensesTest.php; wired into ci.yml in the
 * path-repo-check job alongside check-path-repos/check-child-lifetimes.
 */
final class CheckLibLicenses
{
    public const UPWARD_LINK = '](../LICENSE)';

    /** Derive the package set exactly as sync-sugarcraft.yml enumerates it. */
    public static function derivePackages(string $root): array
    {
        $script = $root . '/scripts/affected-libs.php';
        if (!is_file($script)) {
            self::refuse('cannot find the package enumerator at ' . $script);
        }

        $handle = @proc_open(
            [PHP_BINARY, $script, '--all'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($handle)) {
            self::refuse('could not launch ' . $script);
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($handle);
        if ($rc !== 0) {
            self::refuse('package enumerator exited ' . $rc . ': ' . trim($stderr));
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || !isset($decoded['all_libs']) || !is_array($decoded['all_libs'])) {
            self::refuse('package enumerator printed no `all_libs` list on stdout');
        }

        // An empty set means the guard would iterate nothing and report
        // perfect compliance — a census of zero is a broken census, not a
        // clean one.
        if ($decoded['all_libs'] === []) {
            self::refuse('package enumerator returned an empty set — refusing to certify nothing');
        }

        $packages = [];
        foreach ($decoded['all_libs'] as $lib) {
            if (!is_string($lib) || !is_dir($root . '/' . $lib)) {
                // The workflow skips non-directories; the guard treats a
                // directory-less entry as the enumeration lying about the tree.
                self::refuse('enumerator names a missing directory: ' . var_export($lib, true));
            }
            $packages[] = $lib;
        }
        sort($packages);

        return $packages;
    }

    /**
     * @param  list<string> $packages
     * @return array{missing: list<string>, drifted: list<string>, upwardLinks: list<string>}
     */
    public static function inspect(string $root, array $packages): array
    {
        $rootLicense = $root . '/LICENSE';
        if (!is_readable($rootLicense)) {
            self::refuse('root LICENSE is unreadable at ' . $rootLicense);
        }
        $rootDigest = @md5_file($rootLicense);
        if (!is_string($rootDigest)) {
            self::refuse('root LICENSE could not be hashed at ' . $rootLicense);
        }

        $missing = [];
        $drifted = [];
        $upwardLinks = [];
        foreach ($packages as $pkg) {
            $license = $root . '/' . $pkg . '/LICENSE';
            if (!is_file($license)) {
                $missing[] = $pkg;
            } elseif (@md5_file($license) !== $rootDigest) {
                $drifted[] = $pkg;
            }

            foreach (glob($root . '/' . $pkg . '/*.md') ?: [] as $doc) {
                $text = @file_get_contents($doc);
                if (is_string($text) && str_contains($text, self::UPWARD_LINK)) {
                    $upwardLinks[] = $pkg . '/' . basename($doc);
                }
            }
        }

        return ['missing' => $missing, 'drifted' => $drifted, 'upwardLinks' => $upwardLinks];
    }

    /**
     * @param  array{missing: list<string>, drifted: list<string>, upwardLinks: list<string>} $report
     * @return list<string>
     */
    public static function verdict(array $report): array
    {
        $problems = [];
        foreach ($report['missing'] as $pkg) {
            $problems[] = 'MISSING ' . $pkg . '/LICENSE (copy of root LICENSE — run with --fix)';
        }
        foreach ($report['drifted'] as $pkg) {
            $problems[] = 'DRIFTED ' . $pkg . '/LICENSE (bytes differ from root LICENSE — run with --fix)';
        }
        foreach ($report['upwardLinks'] as $file) {
            $problems[] = 'UPWARD-LINK ' . $file . ' references ' . self::UPWARD_LINK . ' (dead in the split repo — link ](LICENSE))';
        }

        return $problems;
    }

    /**
     * Copy the root LICENSE over every missing/drifted package license.
     * Upward links are prose and never auto-rewritten.
     *
     * @param  array{missing: list<string>, drifted: list<string>, upwardLinks: list<string>} $report
     */
    public static function applyFix(string $root, array $report): int
    {
        $source = $root . '/LICENSE';
        $fixed = 0;
        foreach ([...$report['missing'], ...$report['drifted']] as $pkg) {
            if (!copy($source, $root . '/' . $pkg . '/LICENSE')) {
                self::refuse('could not copy root LICENSE into ' . $pkg . '/');
            }
            ++$fixed;
        }

        return $fixed;
    }

    private static function refuse(string $message): never
    {
        fwrite(STDERR, 'check-lib-licenses: ' . $message . "\n");
        exit(2);
    }

    public static function usage(): string
    {
        return <<<TEXT
            check-lib-licenses.php — every split package must ship the root LICENSE verbatim.

            Derives the package set with the same rule as .github/workflows/sync-sugarcraft.yml
            (scripts/affected-libs.php --all -> all_libs), then asserts each package directory
            carries a LICENSE byte-identical to the root LICENSE and that no top-level package
            .md file links `](../LICENSE)` (dangling after the single-prefix split).

            Usage (options are `=`-form only):
              php tools/check-lib-licenses.php              check the current tree
              php tools/check-lib-licenses.php --fix        copy root LICENSE over missing/drifted files
              php tools/check-lib-licenses.php --root=DIR   run against another checkout (tests)
              php tools/check-lib-licenses.php --list       print the derived package set, one per line
              php tools/check-lib-licenses.php --help       this text

            Exit codes: 0 clean, 1 offending paths (printed), 2 the check could not run.

            TEXT;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    $root = dirname(__DIR__);
    $fix = false;
    $list = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo CheckLibLicenses::usage();
            exit(0);
        }
        if ($arg === '--fix') {
            $fix = true;
            continue;
        }
        if ($arg === '--list') {
            $list = true;
            continue;
        }
        if (str_starts_with($arg, '--root=')) {
            $root = rtrim((string) substr($arg, strlen('--root=')), '/');
            continue;
        }
        fwrite(STDERR, 'check-lib-licenses: unknown argument ' . $arg . " (options are `=`-form; see --help)\n");
        exit(2);
    }
    if (!is_dir($root)) {
        fwrite(STDERR, "check-lib-licenses: --root is not a directory: {$root}\n");
        exit(2);
    }

    $packages = CheckLibLicenses::derivePackages($root);
    if ($list) {
        foreach ($packages as $pkg) {
            echo $pkg . "\n";
        }
        exit(0);
    }

    $report = CheckLibLicenses::inspect($root, $packages);
    if ($fix) {
        $fixed = CheckLibLicenses::applyFix($root, $report);
        if ($fixed > 0) {
            echo 'check-lib-licenses: copied root LICENSE into ' . $fixed . " package(s)\n";
        }
        $report = CheckLibLicenses::inspect($root, $packages);
    }

    $problems = CheckLibLicenses::verdict($report);
    foreach ($problems as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    printf(
        "check-lib-licenses: %d packages, %d problems\n",
        count($packages),
        count($problems),
    );
    exit($problems === [] ? 0 : 1);
}

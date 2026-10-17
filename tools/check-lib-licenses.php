<?php

declare(strict_types=1);

/**
 * check-lib-licenses.php — every split package must ship the root LICENSE verbatim.
 *
 * ISS-120 (sugar_issues.md): the split repos are minted by
 * `.github/workflows/sync-sugarcraft.yml` with a SINGLE `splitsh-lite
 * --prefix="$lib"` per package (multi-prefix is a parse error in the pinned
 * splitsh-lite v1.0.1). A root-only LICENSE therefore never reaches a split
 * repo, and any package link that climbs OUT of the package lands on a file
 * the split never carries. The fix is per-package LICENSE files — this guard
 * enforces presence and resolvability: a real, non-symlink `<pkg>/LICENSE`
 * byte-identical to the root LICENSE, and no upward LICENSE link anywhere in
 * package markdown. Whether a README badges the license at all is prose, not
 * a check — the guard certifies the file is there and reachable, not that
 * anyone links it.
 *
 * The package set is derived with the SAME rule the sync workflow uses —
 * `php scripts/affected-libs.php --all` → JSON `all_libs` — so the guard can
 * never drift from the split roster (one source of truth, no hardcoded count).
 *
 * Checks, per package:
 *   - `<pkg>/LICENSE` exists as a REAL file, not a symlink — `is_file()` and
 *     `md5_file()` both follow links, so a byte-check alone would certify a
 *     `LICENSE -> ../LICENSE` link that the split ships as a dangling
 *     reference;
 *   - its bytes are identical to the root LICENSE (md5);
 *   - no `.md` file at any depth under the package (excluding `vendor/` and
 *     `node_modules/`) upward-links a LICENSE — the pattern matches the
 *     canonical `](../LICENSE)` and every deeper evasion shape that starts
 *     with `](../` and ends in `LICENSE)`.
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
 *   1  a package is missing/drifted/symlinked, or an upward LICENSE link
 *      was found
 *   2  the check could not run (bad --root, enumeration failure, unreadable
 *      bytes) — fail closed: a guard that cannot see the tree reports nothing
 *
 * Guarded by tools/tests/CheckLibLicensesTest.php; wired into ci.yml in the
 * path-repo-check job alongside check-path-repos/check-child-lifetimes.
 */
final class CheckLibLicenses
{
    /** The canonical upward-link shape; verdict messages cite the matched variant. */
    public const UPWARD_LINK = '](../LICENSE)';

    /**
     * Any markdown link that starts with `](../` and resolves to `LICENSE)` —
     * `](../LICENSE)`, `](../../LICENSE)`, `](../x/LICENSE)`. The trailing
     * group demands a directory boundary before the name, so `](../FOOLICENSE)`
     * is not an offender.
     */
    public const UPWARD_LINK_PATTERN = '~\]\((?:\.\./)+(?:[^)]*/)?LICENSE\)~';

    /** Directory names the markdown sweep never descends into, at any depth. */
    private const SWEEP_EXCLUDED_DIRS = ['vendor', 'node_modules'];

    /** The first upward LICENSE link in $text, or null when there is none. */
    public static function upwardLinkIn(string $text): ?string
    {
        return preg_match(self::UPWARD_LINK_PATTERN, $text, $match) === 1 ? $match[0] : null;
    }

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
     * @return array{missing: list<string>, drifted: list<string>, symlinked: list<string>, upwardLinks: array<string, string>}
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
        $symlinked = [];
        $upwardLinks = [];
        foreach ($packages as $pkg) {
            $license = $root . '/' . $pkg . '/LICENSE';
            // is_link FIRST: is_file() and md5_file() follow symlinks, so a
            // `LICENSE -> ../LICENSE` link would sail through the byte check
            // and split into a dangling reference anyway.
            if (is_link($license)) {
                $symlinked[] = $pkg;
            } elseif (!is_file($license)) {
                $missing[] = $pkg;
            } elseif (@md5_file($license) !== $rootDigest) {
                $drifted[] = $pkg;
            }

            foreach (self::packageMarkdownDocs($root, $pkg) as $doc) {
                $text = @file_get_contents($doc);
                if (!is_string($text)) {
                    continue;
                }
                $link = self::upwardLinkIn($text);
                if ($link !== null) {
                    $upwardLinks[$pkg . '/' . substr($doc, strlen($root . '/' . $pkg . '/'))] = $link;
                }
            }
        }

        return [
            'missing' => $missing,
            'drifted' => $drifted,
            'symlinked' => $symlinked,
            'upwardLinks' => $upwardLinks,
        ];
    }

    /**
     * Every `.md` file under a package at any depth, except those under a
     * `vendor/` or `node_modules/` directory — vendored prose is not part of
     * the split and its links are not ours to police. Sorted so the report is
     * deterministic regardless of filesystem order (glob() used to sort).
     *
     * The directory must exist; derivePackages() already refuses an
     * enumerator name with no directory behind it.
     *
     * @return list<string> absolute paths
     */
    private static function packageMarkdownDocs(string $root, string $pkg): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root . '/' . $pkg, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::SWEEP_EXCLUDED_DIRS, true);
                    }
                    return $current->isFile() && str_ends_with($current->getFilename(), '.md');
                },
            ),
        );

        $docs = [];
        foreach ($iterator as $file) {
            $docs[] = $file->getPathname();
        }
        sort($docs, SORT_STRING);

        return $docs;
    }

    /**
     * @param  array{missing: list<string>, drifted: list<string>, symlinked: list<string>, upwardLinks: array<string, string>} $report
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
        foreach ($report['symlinked'] as $pkg) {
            $problems[] = 'SYMLINKED ' . $pkg . '/LICENSE (a symlink is not a file the split can carry — replace it with the real copy, then --fix)';
        }
        foreach ($report['upwardLinks'] as $file => $link) {
            $problems[] = 'UPWARD-LINK ' . $file . ' references ' . $link . ' (dead in the split repo — link ](LICENSE))';
        }

        return $problems;
    }

    /**
     * Copy the root LICENSE over every missing/drifted package license.
     * Upward links are prose and never auto-rewritten; a symlinked LICENSE is
     * left alone too, because --fix copying over the LINK would write through
     * it to whatever target it names.
     *
     * @param  array{missing: list<string>, drifted: list<string>, symlinked: list<string>, upwardLinks: array<string, string>} $report
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
            carries a real (non-symlink) LICENSE byte-identical to the root LICENSE, and that no
            package .md file at any depth — vendor/ and node_modules/ excluded — upward-links a
            LICENSE (](../LICENSE) and every deeper shape that starts with ](../ and ends in
            LICENSE); all are dangling after the single-prefix split.

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

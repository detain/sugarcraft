<?php

declare(strict_types=1);

/**
 * tools/build-phar.php — builds the single-file candy-sql PHAR.
 *
 * Ported from candy-code/candy-top's build-phar.php (the DST-1 program); the
 * guard law, the integrity preflight, and the stub-is-the-bin design are all
 * inherited verbatim, so read those files' headers for the full reasoning.
 * The short form:
 *
 *   php -d phar.readonly=0 tools/build-phar.php [--out dist/candy-sql.phar] [--gz]
 *
 * `-d phar.readonly=0` is BUILD-ONLY, never a runtime requirement.
 *
 * REPRODUCIBILITY OF THE STAMP: `metadata['built_at']` honors
 * SOURCE_DATE_EPOCH (a release workflow pins it to the tag's commit date);
 * unset, the wall clock answers. The version stamp is read back at runtime by
 * `Version::current()` — inside an archive the manifest wins over the
 * composer-mode InstalledVersions answer, because a shipped artifact that
 * prints the frozen vendor's build-root version for its whole life is the
 * DST-1 lie in another costume.
 *
 * WHY THE BUILD MUST RUN FROM A CLEAN PUBLISHED VENDOR
 * ====================================================
 * This is a monorepo, and `scripts/refresh-deps.php --mode=linked` swaps
 * `vendor/sugarcraft/*` for SYMLINKS into the working tree. A phar built in
 * that state would follow the links and freeze CURRENT WORKING-TREE CODE —
 * uncommitted files included — into the artifact, silently diverging from
 * the tag it claims to ship. The integrity preflight below refuses dirty
 * trees, detached HEADs, and git-unanswerable checkouts by default;
 * `--vendor=monorepo` is the loud dev-only hatch and skips ONLY that
 * preflight (the symlink guard still stands).
 *
 * WHAT IS PACKAGED: bin/, src/, lang/, data/, vendor/ and LICENSE, at the
 * archive root mirroring the package layout, so the bin's `__DIR__ . '/../'`
 * candidates resolve inside the archive and every `__DIR__`-relative DATA read
 * keeps the layout it was written against — crucially Admin\\Reports\\Catalog and
 * Admin\\Variables\\Catalog, which load the ~412 KB data/ JSON payload through
 * file_get_contents with the default `__DIR__ . '/../../../data'` root.
 * file_get_contents DOES read the phar:// wrapper (unlike glob — the
 * candy-vcr lesson), so data/ needs only to BE in the manifest to work; its
 * absence would silently strip the admin dashboards' entire metric catalog
 * from the shipped artifact.
 *
 * The STUB is the source of bin/candy-sql itself, byte-for-byte, with
 * `__HALT_COMPILER();` appended. PHP refuses `phar://` URLs as a main script
 * (measured); as the stub, `__FILE__` is the archive's real path and the bin's
 * DST-1 guard addresses the archive as `'phar://' . __FILE__`.
 */

$package = dirname(__DIR__);
$outArg = $package . '/dist/candy-sql.phar';
$gz = false;
$version = null;
$vendorMode = 'published';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--gz') {
        $gz = true;
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $outArg = substr($arg, 6);
        continue;
    }
    if (str_starts_with($arg, '--version=')) {
        $version = substr($arg, 10);
        continue;
    }
    if (str_starts_with($arg, '--vendor=')) {
        $candidate = substr($arg, 9);
        if ($candidate !== 'published' && $candidate !== 'monorepo') {
            fwrite(STDERR, "build-phar: --vendor={$candidate} is not a mode (expected published or monorepo).\n");
            exit(2);
        }
        $vendorMode = $candidate;
        continue;
    }
    fwrite(STDERR, "build-phar: unrecognized option {$arg} (usage: php -d phar.readonly=0 tools/build-phar.php [--out=PATH] [--gz] [--version=X] [--vendor=published|monorepo])\n");
    exit(2);
}

// Guard 1: the build needs to WRITE an archive; the runtime never does.
if (ini_get('phar.readonly') !== '' && filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "build-phar: phar.readonly is On; run the build as `php -d phar.readonly=0 tools/build-phar.php` (this flag is never a runtime setting).\n");
    exit(2);
}

// Guard 2 (the integrity preflight): the archive may only ship what its
// commit stamp claims. Every git question is asked with 2>&1 so a failing
// git reads as an unverifiable tree — refused, not waved through.
if ($vendorMode === 'published') {
    $headRef = trim((string) @shell_exec('git -C ' . escapeshellarg($package) . ' rev-parse --abbrev-ref HEAD 2>&1'));
    $porcelain = (string) @shell_exec('git -C ' . escapeshellarg($package) . ' status --porcelain 2>&1');
    if ($headRef === '' || str_contains($headRef, 'fatal:') || str_contains($porcelain, 'fatal:')) {
        fwrite(STDERR, "build-phar: refusing to build — the source tree's integrity cannot be verified with git (no repository, git unavailable, or git refused to answer). Commit the tree, or pass --vendor=monorepo to build from working-tree state deliberately (dev-only; never for a published artifact).\n");
        exit(2);
    }
    if ($headRef === 'HEAD') {
        fwrite(STDERR, "build-phar: refusing to build from a detached HEAD — the artifact could not be attributed to a named ref. Attach the checkout (git checkout -B build HEAD), or pass --vendor=monorepo (dev-only).\n");
        exit(2);
    }
    $dirty = array_values(array_filter(explode("\n", $porcelain), static fn (string $line): bool => $line !== ''));
    if ($dirty !== []) {
        fwrite(STDERR, sprintf(
            "build-phar: refusing to build from a dirty source tree — %d path(s) differ from branch %s. Uncommitted code would freeze into the artifact while its commit stamp names a state that does not contain it. Commit or revert, or pass --vendor=monorepo to build from working-tree state deliberately (dev-only; never for a published artifact).\n",
            count($dirty),
            $headRef,
        ));
        exit(2);
    }
} else {
    fwrite(STDERR, "build-phar: --vendor=monorepo given — INTEGRITY PREFLIGHT DISABLED. This artifact is built from the WORKING TREE, uncommitted edits included. Development use only; it must never be published.\n");
}

// Guard 3: the packaged vendor must exist.
if (!is_file($package . '/vendor/autoload.php')) {
    fwrite(STDERR, "build-phar: {$package}/vendor/autoload.php is missing — run `composer install --no-dev --prefer-dist` in candy-sql/ first.\n");
    exit(2);
}

/** Roots packaged at the archive, verbatim. Everything else stays out. */
const INCLUDE_ROOTS = ['bin', 'src', 'lang', 'data', 'vendor'];
/** Loose files packaged at the archive root. */
const INCLUDE_FILES = ['LICENSE'];
/** Never shipped, at any depth. */
const EXCLUDE_SEGMENTS = ['.git', '.phpunit.cache', 'node_modules'];

/**
 * Guard 4 (the poisoning guard): no symlink anywhere in a packaged tree.
 * The walk is scandir-based, not glob-based, per the house law the same
 * artifact must obey at runtime (see FontLoader).
 */
$roots = [];
foreach (INCLUDE_ROOTS as $root) {
    $path = $package . '/' . $root;
    if (!is_dir($path)) {
        fwrite(STDERR, "build-phar: expected packaged root {$root}/ is missing.\n");
        exit(2);
    }
    $roots[] = $path;
}

$files = [];
$walk = static function (string $dir) use (&$walk, &$files, $package): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_link($path)) {
            fwrite(STDERR, "build-phar: refusing to package symlinked tree {$path} — this vendor/ is a LINKED monorepo install; the phar would freeze working-tree code. Rebuild from `composer install --no-dev --prefer-dist` (or `php scripts/refresh-deps.php --mode=published`).\n");
            exit(2);
        }
        if (in_array($name, EXCLUDE_SEGMENTS, true)) {
            continue;
        }
        if (is_dir($path)) {
            $walk($path);
            continue;
        }
        $files[] = $path;
    }
};
foreach ($roots as $root) {
    $walk($root);
}
foreach (INCLUDE_FILES as $loose) {
    if (is_file($package . '/' . $loose)) {
        $files[] = $package . '/' . $loose;
    }
}
sort($files);
fwrite(STDERR, sprintf("build-phar: walk found %d files\n", count($files)));

// Bloat report (not a refusal): dev packages in vendor/ mean the build ran
// `composer install` without --no-dev.
$withDev = is_dir($package . '/vendor/phpunit');

$binSource = file_get_contents($package . '/bin/candy-sql');
if ($binSource === false) {
    fwrite(STDERR, "build-phar: cannot read bin/candy-sql.\n");
    exit(2);
}
if (!str_starts_with($binSource, "#!/usr/bin/env php\n<?php")) {
    // The stub IS this file; the shebang+open-tag shape is what makes the
    // archive directly executable. A respell that breaks the shape must not
    // ship silently — PharBuildScriptTest pins both sides of this check.
    fwrite(STDERR, "build-phar: bin/candy-sql no longer starts with the shebang+<?php stub shape; refusing.\n");
    exit(2);
}

if ($version === null) {
    // Namespace-filtered: this monorepo's tag stream is shared by every lib,
    // so an unfiltered `describe --tags` would stamp some other package's
    // tag onto a candy-sql artifact. No candy-sql tag reachable => dev-build,
    // which versionString() refuses to read as a version.
    $described = @shell_exec('git -C ' . escapeshellarg($package) . ' describe --tags --abbrev=0 --match ' . escapeshellarg('candy-sql/v*') . ' 2>/dev/null');
    $version = is_string($described) && trim($described) !== '' ? trim($described) : 'dev-build';
}
$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($package) . ' rev-parse HEAD 2>/dev/null'));

$sourceEpoch = getenv('SOURCE_DATE_EPOCH');
$builtAt = preg_match('/^\d+$/', $sourceEpoch === false ? '' : $sourceEpoch) === 1
    ? (new DateTimeImmutable('@' . (int) $sourceEpoch))->setTimezone(new DateTimeZone('UTC'))->format('c')
    : date('c');

@mkdir(dirname($outArg), 0o755, true);
@unlink($outArg);

$phar = new Phar($outArg, 0, 'candy-sql.phar');
$phar->startBuffering();
$added = 0;
foreach ($files as $file) {
    $phar->addFile($file, ltrim(substr($file, strlen($package)), '/'));
    ++$added;
}
$phar->setMetadata([
    'version'   => $version,
    'built_at'  => $builtAt,
    'commit'    => $commit !== '' ? $commit : null,
    'php_built' => PHP_VERSION,
    'entry'     => 'bin/candy-sql (as stub)',
]);
$phar->setStub($binSource . "\n__HALT_COMPILER();");
$phar->stopBuffering();

if ($gz) {
    if (!extension_loaded('zlib')) {
        fwrite(STDERR, "build-phar: --gz needs ext-zlib at build time (and at RUNTIME to read the archive).\n");
        exit(2);
    }
    $compressed = $phar->compress(\Phar::GZ);
    $compressed->stopBuffering();
    unset($compressed);
    unlink($outArg);
    rename($outArg . '.gz', $outArg);
}

$bytes = filesize($outArg);
printf(
    "build-phar: %s — %d files, %.1f MB%s%s\n",
    $outArg,
    count($files),
    (($bytes === false ? 0 : $bytes) / 1048576),
    $gz ? ' (GZ)' : '',
    $withDev ? ' [WARNING: vendor/ still carries dev packages; run composer install --no-dev for release artifacts]' : '',
);
printf("build-phar: sha256 %s\n", hash_file('sha256', $outArg));
// The stub carries a shebang; without the executable bit the shipped artifact
// answers `./candy-sql.phar` with EACCES (measured on candy-code's first
// real build). Make the direct form the honest one.
@chmod($outArg, 0o755);

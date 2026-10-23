<?php

declare(strict_types=1);

/**
 * tools/build-phar.php — builds the single-file candy-git-server PHAR.
 *
 * Mirrors candy-top/tools/build-phar.php (the DST-1 program); the guard
 * structure, refusal wording shape, stamp fields, and stub law are the port.
 *
 * Run it as:
 *   php -d phar.readonly=0 tools/build-phar.php [--out dist/candy-git-server.phar] [--gz]
 *
 * `-d phar.readonly=0` is BUILD-ONLY, never a runtime requirement: the app
 * never mutates its own archive, so the shipped php.ini default of On is
 * exactly right for users.
 *
 * REPRODUCIBILITY OF THE STAMP: `metadata['built_at']` honors
 * SOURCE_DATE_EPOCH when the environment exports one (the release workflow
 * pins it to the tag's commit date; the stamp is then that instant in UTC and
 * rebuilds agree byte-wise on this field). Unset, the wall clock answers. The
 * version stamp itself is read back at runtime by
 * `SugarCraft\GitServer\Support\Version::fromPharMetadata()` — candy-code's
 * DST-1 lesson: the frozen vendor/ inside the artifact makes Composer's
 * InstalledVersions report the build root's dev guess, so a stamp NOTHING
 * consumes means `--version` lies. PharBuildScriptTest pins the stamp field
 * and its reader agree.
 *
 * WHY THE BUILD MUST RUN FROM A CLEAN PUBLISHED VENDOR
 * ====================================================
 * This is a monorepo, and `scripts/refresh-deps.php --mode=linked` swaps
 * `vendor/sugarcraft/*` for SYMLINKS into the working tree. A phar built in
 * that state would follow the links and freeze the CURRENT WORKING-TREE CODE
 * — including uncommitted files — into the artifact, silently diverging from
 * the tag it claims to ship. The refusal below is mechanical (no symlink
 * inside any packaged tree), not advisory. The honest build state is
 * `composer install --no-dev --prefer-dist` in a clean checkout (or
 * `php scripts/refresh-deps.php --mode=published`).
 *
 * `--no-dev` is what keeps phpunit et al. OUT of the artifact; a vendor/
 * that still carries dev packages is not refused (bloat, not poison), but
 * the summary line reports it.
 *
 * THE FAIL-CLOSED INTEGRITY PREFLIGHT (GUARD 2)
 * =============================================
 * A vendor-shape check alone is not enough: a DIRTY source tree with a
 * PUBLISHED vendor freezes uncommitted working-tree code into the artifact
 * while the metadata commit stamp names a state that does not contain it —
 * disclosure is not refusal. The build therefore stops, by default, when the
 * source tree does not match its commit: `git status --porcelain` must be
 * empty and HEAD must sit on a named branch. A checkout whose integrity git
 * cannot answer for (no repository, git missing, git erroring) is refused the
 * same way — the door fails CLOSED, never open on a read failure.
 *
 * `--vendor=monorepo` is the deliberate dev escape hatch for building inside
 * this repository between commits: it skips ONLY the integrity preflight (the
 * symlink guard below still stands — freezing SIBLING working trees remains
 * poison), prints a loud notice on stderr, and must never produce a published
 * artifact.
 *
 * WHAT IS PACKAGED, and why the entry point is special
 * ====================================================
 * The archive root mirrors the package root (bin/, src/, lang/, vendor/,
 * LICENSE) so bin/candy-git-server's autoload candidate
 * (`phar://.../vendor/autoload.php`) resolves inside the archive with no
 * composer-mode change, and so the `__DIR__`-relative data read in
 * src/Lang.php (`__DIR__ . '/../lang'`) keeps the layout it was written
 * against. The repo data path is RUNTIME (env / sys_get_temp_dir), never a
 * packaged tree, so nothing else needs freezing.
 *
 * The STUB is the source of bin/candy-git-server itself, byte-for-byte, with
 * `__HALT_COMPILER();` appended — not a generated `require
 * 'phar://.../bin/candy-git-server'` one-liner. That is load-bearing: PHP
 * refuses `phar://` URLs as a main script ("Could not open input file",
 * measured in candy-code's first real build). As stub, `__FILE__` is the
 * archive's real path — and Phar::running() answers '' in the stub context
 * (measured), so the guard's phar candidate is `'phar://' . __FILE__`, which
 * finds `vendor/autoload.php` inside the archive.
 */

$package = dirname(__DIR__);
$outArg = $package . '/dist/candy-git-server.phar';
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

// Guard 3: the packaged vendor must exist and be a published install.
if (!is_file($package . '/vendor/autoload.php')) {
    fwrite(STDERR, "build-phar: {$package}/vendor/autoload.php is missing — run `composer install --no-dev --prefer-dist` in candy-git-server/ first.\n");
    exit(2);
}

/** Roots packaged at the archive, verbatim. Everything else stays out. */
const INCLUDE_ROOTS = ['bin', 'src', 'lang', 'vendor'];
/** Loose files packaged at the archive root. */
const INCLUDE_FILES = ['LICENSE'];
/** Never shipped, at any depth. */
const EXCLUDE_SEGMENTS = ['.git', '.phpunit.cache', 'node_modules'];

/**
 * Guard 4 (the poisoning guard): no symlink anywhere in a packaged tree,
 * and no hard-to-see directory escape. scandir does not follow directory
 * symlinks for RECURSION, but it reports them, so every entry is checked; a
 * link inside vendor/sugarcraft is the exact linked-mode fingerprint
 * refresh-deps leaves behind. The walk is scandir-based, not glob-based, per
 * the house law the same artifact must obey at runtime.
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

$binSource = file_get_contents($package . '/bin/candy-git-server');
if ($binSource === false) {
    fwrite(STDERR, "build-phar: cannot read bin/candy-git-server.\n");
    exit(2);
}
if (!str_starts_with($binSource, "#!/usr/bin/env php\n<?php")) {
    // The stub IS this file; the shebang+open-tag shape is what makes the
    // archive directly executable. A respell that breaks the shape must not
    // ship silently — the bin-stub guard pins both sides of this check.
    fwrite(STDERR, "build-phar: bin/candy-git-server no longer starts with the shebang+<?php stub shape; refusing.\n");
    exit(2);
}

if ($version === null) {
    // Namespace-filtered: this monorepo's tag stream is shared by every lib,
    // so an unfiltered `describe --tags` would stamp some other package's tag
    // onto a candy-git-server artifact. No candy-git-server tag reachable =>
    // dev-build, which Version::fromPharMetadata() refuses to read as a
    // version.
    $described = @shell_exec('git -C ' . escapeshellarg($package) . ' describe --tags --abbrev=0 --match ' . escapeshellarg('candy-git-server/v*') . ' 2>/dev/null');
    $version = is_string($described) && trim($described) !== '' ? trim($described) : 'dev-build';
}
$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($package) . ' rev-parse HEAD 2>/dev/null'));

// Reproducibility: honor SOURCE_DATE_EPOCH so a release workflow can export
// the tag's commit date and get a byte-stable built_at; unset, the wall clock
// answers exactly as before.
$sourceEpoch = getenv('SOURCE_DATE_EPOCH');
$builtAt = preg_match('/^\d+$/', $sourceEpoch === false ? '' : $sourceEpoch) === 1
    ? (new DateTimeImmutable('@' . (int) $sourceEpoch))->setTimezone(new DateTimeZone('UTC'))->format('c')
    : date('c');

@mkdir(dirname($outArg), 0o755, true);
@unlink($outArg);

$phar = new Phar($outArg, 0, 'candy-git-server.phar');
$phar->startBuffering();
$added = 0;
$tick  = hrtime(true);
foreach ($files as $file) {
    $phar->addFile($file, ltrim(substr($file, strlen($package)), '/'));
    if (++$added % 1000 === 0) {
        fwrite(STDERR, sprintf("build-phar: added %d/%d (%.1fs)\n", $added, count($files), (hrtime(true) - $tick) / 1e9));
    }
}
fwrite(STDERR, sprintf("build-phar: all %d files buffered (%.1fs); sealing (per-entry signatures + tar write)\n", $added, (hrtime(true) - $tick) / 1e9));
$phar->setMetadata([
    'version'   => $version,
    'built_at'  => $builtAt,
    'commit'    => $commit !== '' ? $commit : null,
    'php_built' => PHP_VERSION,
    'entry'     => 'bin/candy-git-server (as stub)',
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
    // compress() writes {$outArg}.gz and leaves the original; the caller
    // asked for a gz artifact at --out, so replace.
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
// answers `./candy-git-server.phar` with EACCES and only `php candy-git-server.phar`
// works (measured on candy-code's first real build). Make the direct form the
// honest one.
@chmod($outArg, 0o755);

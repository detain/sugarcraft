<?php

declare(strict_types=1);

/**
 * tools/build-phar.php — builds the single-file candy-code PHAR (DST-1).
 *
 * Run it as:
 *   php -d phar.readonly=0 tools/build-phar.php [--out dist/candy-code.phar] [--gz] [--manifest-out PATH]
 *
 * `-d phar.readonly=0` is BUILD-ONLY, never a runtime requirement: the app
 * never mutates its own archive (a future `self-update` replaces the FILE,
 * not the phar contents), so the shipped php.ini default of On is exactly
 * right for users.
 *
 * REPRODUCIBILITY OF THE STAMP: `metadata['built_at']` honors
 * SOURCE_DATE_EPOCH when the environment exports one (a release workflow
 * can pin it to the tag's commit date; the stamp is then that instant in
 * UTC and rebuilds agree byte-wise on this field). Unset, the wall clock
 * answers — the historical behavior. The version stamp itself is read back
 * at runtime by `Version::fromPharMetadata()` (DST-1/DST-3: nothing used to
 * consume it, so the shipped phar reported the frozen dev-master).
 *
 * REPRODUCIBILITY OF THE ENTRIES (C-SES-6.b): phar entries carry a
 * modification time, so an epoch-pinned build first `touch()`es every
 * packaged file to SOURCE_DATE_EPOCH; with the epoch unset the files are
 * left exactly as found. The touch moves mtimes only, never content — and
 * it runs AFTER the integrity preflight, whose `git status --porcelain`
 * reads content, so a published-mode build cannot touch its way into a
 * dirty tree. The honest consequence is for local runs: a build with the
 * epoch exported invalidates git's stat cache across the checkout (the
 * next `git status` re-hashes every packaged file, content-clean results
 * unchanged). The release workflow checks out a throwaway clone, so it
 * never pays that cost twice; developers who care build from a copy.
 * Measured on PHP 8.3.6: `addFile`/`addFromString` seal entries with mtime
 * 0 and the source mtime reaches neither the tar header nor the hash — so
 * on that runtime the touch is belt-and-braces for the format, and what it
 * provably decides is the packaged files' own mtimes (which PharCompatTest
 * pins) plus the entry clock on any runtime that does seal it.
 *
 * THE PACKAGES MANIFEST (C-SES-6.b): the artifact states what it froze.
 * `vendor/composer/installed.json` is folded into the metadata under
 * `packages` as `name => reference` (source reference, else dist reference,
 * else the recorded version — whatever composer itself attested). With the
 * release workflow's lock-less `dev-master` resolution (disclosed in
 * .github/workflows/release-phar.yml), this is the only per-build record of
 * which sibling commit rode the archive. `--manifest-out=PATH` writes the
 * same map as JSON next to the artifact for release notes; a missing or
 * unparsable installed.json stores `packages: null` and warns on stderr —
 * unknown is honest, an empty map would be a fabrication.
 *
 * WHY THE BUILD MUST RUN FROM A CLEAN PUBLISHED VENDOR
 * ====================================================
 * This is a monorepo, and {@see scripts/refresh-deps.php --mode=linked}
 * swaps `vendor/sugarcraft/*` for SYMLINKS into the working tree. A phar
 * built in that state would follow the links and freeze the CURRENT
 * WORKING-TREE CODE — including uncommitted files — into the artifact,
 * silently diverging from the tag it claims to ship. The refusal below is
 * mechanical (no symlink inside any packaged tree), not advisory. The honest
 * build state is `composer install --no-dev --prefer-dist` in a clean
 * checkout — what .github/workflows/release-phar.yml does on every tag.
 *
 * `--no-dev` is what keeps phpunit et al. OUT of the artifact; a vendor/
 * that still carries dev packages is not refused (bloat, not poison), but
 * the summary line reports it. There is deliberately NO provider-SDK
 * filter: aws-sdk-php, google/cloud-ai-platform, guzzle, openai-php and
 * the react/ratchet stack all ship, because the published composer set
 * needs them and a "slim" phar that dies the first time someone configures
 * Bedrock is a worse product than a large honest one. Weight: a published
 * install packs to roughly 30 MB of PHP/JSON; the uncompressed phar of the
 * runtime vendor set measures about 118 MiB on a real build (the summary
 * prints the real figure; --gz trades a runtime ext-zlib dependency for
 * transfer size).
 *
 * THE FAIL-CLOSED INTEGRITY PREFLIGHT (GUARD 2, rev-w2j2 MAJOR-2)
 * ===============================================================
 * A vendor-shape check alone is not enough: a DIRTY source tree with a
 * PUBLISHED vendor freezes uncommitted working-tree code into the artifact
 * while the metadata commit stamp names a state that does not contain it —
 * disclosure is not refusal. The build therefore stops, by default, when the
 * source tree does not match its commit: `git status --porcelain` must be
 * empty and HEAD must sit on a named branch. A checkout whose integrity git
 * cannot answer for (no repository, git missing, git erroring) is refused the
 * same way — the door fails CLOSED, never open on a read failure. The release
 * workflow satisfies all three legs by construction: it checks out a tag,
 * attaches HEAD to a branch, and installs with no working-tree edits.
 *
 * `--vendor=monorepo` is the deliberate dev escape hatch for building inside
 * this repository between commits: it skips ONLY the integrity preflight (the
 * symlink guard below still stands — freezing SIBLING working trees remains
 * poison), prints a loud notice on stderr, and must never produce a
 * published artifact. PharCompatTest pins all three doors.
 *
 * WHAT IS PACKAGED, and why the entry point is special
 * ====================================================
 * The archive root mirrors the package root (src/, lang/,
 * builtin-commands/, vendor/, bin/, LICENSE, .candy-code/agents/) so
 * bin/candy-code's existing autoload candidate #2 (`__DIR__ . '/../vendor'`)
 * resolves inside the archive with no composer-mode change, and so every
 * `__DIR__`-relative DATA read keeps the layout it was written against.
 * `.candy-code/config.dev.json` is EXCLUDED: it names the repo's dev
 * endpoint and must never ride a published artifact (PharCompatTest pins
 * the exclusion structurally).
 *
 * The STUB is the source of bin/candy-code itself, byte-for-byte, with
 * `__HALT_COMPILER();` appended — not a generated `require
 * 'phar://.../bin/candy-code'` one-liner. That is load-bearing: PHP refuses
 * `phar://` URLs as a main script ("Could not open input file", measured),
 * and `/new`'s cross-root relaunch execs `PHP_BINARY . $argv-script`,
 * which under a require-from-stub layout is exactly such a URL — the
 * relaunch would exit 1 and strand the session. As stub, `__FILE__` is the
 * archive's real path — and Phar::running() answers '' in the stub context
 * (measured on the first real build), so the guard's phar candidate is
 * `'phar://' . __FILE__`, which finds `vendor/autoload.php` inside the
 * archive; and a relaunch re-runs
 * the stub — the phar relaunches itself. PharCompatTest pins both halves.
 */

$package = dirname(__DIR__);
$outArg = $package . '/dist/candy-code.phar';
$gz = false;
$version = null;
$vendorMode = 'published';
$manifestOut = null;

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
    if (str_starts_with($arg, '--manifest-out=')) {
        $manifestOut = substr($arg, 15);
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
    fwrite(STDERR, "build-phar: unrecognized option {$arg} (usage: php -d phar.readonly=0 tools/build-phar.php [--out=PATH] [--gz] [--version=X] [--vendor=published|monorepo] [--manifest-out=PATH])\n");
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
    fwrite(STDERR, "build-phar: {$package}/vendor/autoload.php is missing — run `composer install --no-dev --prefer-dist` in candy-code/ first.\n");
    exit(2);
}

/** Roots packaged at the archive, verbatim. Everything else stays out. */
const INCLUDE_ROOTS = ['bin', 'src', 'lang', 'builtin-commands', 'vendor', '.candy-code/agents'];
/** Loose files packaged at the archive root. */
const INCLUDE_FILES = ['LICENSE'];
/** Never shipped, at any depth. */
const EXCLUDE_SEGMENTS = ['.git', '.phpunit.cache', 'node_modules'];

/**
 * Guard 4 (the poisoning guard): no symlink anywhere in a packaged tree,
 * and no hard-to-see directory escape. RecursiveDirectoryIterator does not
 * follow directory symlinks by default for RECURSION, but it reports them,
 * so every entry is checked; a link inside vendor/sugarcraft is the exact
 * linked-mode fingerprint refresh-deps leaves behind.
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

$binSource = file_get_contents($package . '/bin/candy-code');
if ($binSource === false) {
    fwrite(STDERR, "build-phar: cannot read bin/candy-code.\n");
    exit(2);
}
if (!str_starts_with($binSource, "#!/usr/bin/env php\n<?php")) {
    // The stub IS this file; the shebang+open-tag shape is what makes the
    // archive directly executable. A respell that breaks the shape must not
    // ship silently — PharCompatTest pins both sides of this check.
    fwrite(STDERR, "build-phar: bin/candy-code no longer starts with the shebang+<?php stub shape; refusing.\n");
    exit(2);
}

if ($version === null) {
    // Namespace-filtered: this monorepo's tag stream is shared by every lib,
    // so an unfiltered `describe --tags` would stamp some other package's
    // tag onto a candy-code artifact. No candy-code tag reachable => dev-build,
    // which Version::fromPharMetadata() refuses to read as a version.
    $described = @shell_exec('git -C ' . escapeshellarg($package) . ' describe --tags --abbrev=0 --match ' . escapeshellarg('candy-code/v*') . ' 2>/dev/null');
    $version = is_string($described) && trim($described) !== '' ? trim($described) : 'dev-build';
}
$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($package) . ' rev-parse HEAD 2>/dev/null'));

// Reproducibility (DST-1 minor): honor SOURCE_DATE_EPOCH so a release
// workflow can export the tag's commit date and get a byte-stable
// built_at; unset, the wall clock answers exactly as before.
$sourceEpoch = getenv('SOURCE_DATE_EPOCH');
$epochTs = preg_match('/^\d+$/', $sourceEpoch === false ? '' : $sourceEpoch) === 1
    ? (int) $sourceEpoch
    : null;
$builtAt = $epochTs !== null
    ? (new DateTimeImmutable('@' . $epochTs))->setTimezone(new DateTimeZone('UTC'))->format('c')
    : date('c');

// C-SES-6.b — the packages manifest: name => reference for every package
// composer attests it installed, sealed into the archive's metadata. With
// the release workflow's lock-less dev-master resolution this is the only
// per-build record of WHICH sibling code rode the artifact. Unknown reads
// as null with a loud warning — an empty map would fabricate a success.
$packages = null;
$installedPath = $package . '/vendor/composer/installed.json';
if (is_file($installedPath)) {
    $installedRaw = file_get_contents($installedPath);
    $installed = is_string($installedRaw) ? json_decode($installedRaw, true) : null;
    if (is_array($installed)) {
        // Composer 2 nests the list under "packages"; composer 1 ships it
        // as the top-level array. Both shapes are attested in the wild.
        $list = isset($installed['packages']) && is_array($installed['packages'])
            ? $installed['packages']
            : (array_is_list($installed) ? $installed : null);
        if (is_array($list)) {
            $packages = [];
            foreach ($list as $entry) {
                if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                    // A nameless entry cannot be attributed — the whole map
                    // degrades to null rather than shipping a partial lie.
                    $packages = null;
                    break;
                }
                $reference = null;
                foreach (['source', 'dist'] as $leg) {
                    if (isset($entry[$leg]['reference']) && is_string($entry[$leg]['reference']) && $entry[$leg]['reference'] !== '') {
                        $reference = $entry[$leg]['reference'];
                        break;
                    }
                }
                if ($reference === null) {
                    // No VCS reference attested (non-VCS dist): the recorded
                    // version is the strongest honest claim left.
                    $reference = isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '';
                }
                $packages[$entry['name']] = $reference;
            }
        }
    }
    if ($packages === null) {
        fwrite(STDERR, "build-phar: vendor/composer/installed.json is unreadable or not composer-shaped; the artifact records packages=null (unknown), never an empty map.\n");
    }
} else {
    fwrite(STDERR, "build-phar: vendor/composer/installed.json is missing; the artifact records packages=null (unknown), never an empty map.\n");
}

@mkdir(dirname($outArg), 0o755, true);
@unlink($outArg);

// C-SES-6.b — entry-clock pinning: phar entries carry a modification time,
// so an epoch-pinned build normalizes every packaged file's mtime to it
// first (content is untouched; the integrity preflight above already read
// the tree by content, so this cannot make it dirty). With the epoch unset
// or malformed, files are left exactly as found.
if ($epochTs !== null) {
    foreach ($files as $file) {
        touch($file, $epochTs);
    }
}

$phar = new Phar($outArg, 0, 'candy-code.phar');
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
    'packages'  => $packages,
    'entry'     => 'bin/candy-code (as stub)',
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

// C-SES-6.b — --manifest-out: the same packages map as standalone JSON, for
// release notes and attestation logs. The build already succeeded; a
// DISCLOSURE the caller explicitly asked for must still fail loud, not
// vanish (no fabricated success on the reporting channel either).
if ($manifestOut !== null) {
    $manifestJson = json_encode($packages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $manifestDir = dirname($manifestOut);
    if (!is_dir($manifestDir)) {
        @mkdir($manifestDir, 0o755, true);
    }
    if ($manifestJson === false || @file_put_contents($manifestOut, $manifestJson . "\n") === false) {
        fwrite(STDERR, "build-phar: --manifest-out={$manifestOut} could not be written; refusing to claim a disclosure that did not land.\n");
        exit(2);
    }
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
// answers `./candy-code.phar` with EACCES and only `php candy-code.phar` works
// (measured on the first real build). Make the direct form the honest one.
@chmod($outArg, 0o755);

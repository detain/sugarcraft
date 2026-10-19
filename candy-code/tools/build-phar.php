<?php

declare(strict_types=1);

/**
 * tools/build-phar.php — builds the single-file candy-code PHAR (DST-1).
 *
 * Run it as:
 *   php -d phar.readonly=0 tools/build-phar.php [--out dist/candy-code.phar] [--gz]
 *
 * `-d phar.readonly=0` is BUILD-ONLY, never a runtime requirement: the app
 * never mutates its own archive (a future `self-update` replaces the FILE,
 * not the phar contents), so the shipped php.ini default of On is exactly
 * right for users.
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
 * runtime vendor set lands in the tens of megabytes (the summary prints the
 * real figure; --gz trades a runtime ext-zlib dependency for transfer size).
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
 * archive's real path, the guard's phar candidate (`Phar::running(false)`)
 * finds `vendor/autoload.php` inside the archive, and a relaunch re-runs
 * the stub — the phar relaunches itself. PharCompatTest pins both halves.
 *
 * KNOWN PHAR DEGRADATION (disclosed, not fixed here):
 * AgentPresetRegistry::bundledPresetsDirectory() gates the three bundled
 * example presets with realpath(), which returns false under phar://, so a
 * phar install ships no BUNDLED presets — user/project/home preset
 * directories are unaffected. Every other `__DIR__`-relative DATA site was
 * audited and is wrapper-safe (scandir/is_file/require/file_get_contents).
 * Two of them (ToolCatalog::discover, BuiltInCommands) used glob(), which
 * reads no stream wrapper; they were converted to scandir in this lane
 * precisely so the artifact is not hollow.
 */

$package = dirname(__DIR__);
$outArg = $package . '/dist/candy-code.phar';
$gz = false;
$version = null;

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
    fwrite(STDERR, "build-phar: unrecognized option {$arg} (usage: php -d phar.readonly=0 tools/build-phar.php [--out=PATH] [--gz] [--version=X])\n");
    exit(2);
}

// Guard 1: the build needs to WRITE an archive; the runtime never does.
if (ini_get('phar.readonly') !== '' && filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "build-phar: phar.readonly is On; run the build as `php -d phar.readonly=0 tools/build-phar.php` (this flag is never a runtime setting).\n");
    exit(2);
}

// Guard 2: the packaged vendor must exist and be a published install.
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
 * Guard 3 (the poisoning guard): no symlink anywhere in a packaged tree,
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
    $described = @shell_exec('git -C ' . escapeshellarg($package) . ' describe --tags --abbrev=0 2>/dev/null');
    $version = is_string($described) && trim($described) !== '' ? trim($described) : 'dev-build';
}
$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($package) . ' rev-parse HEAD 2>/dev/null'));

@mkdir(dirname($outArg), 0o755, true);
@unlink($outArg);

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
    'built_at'  => date('c'),
    'commit'    => $commit !== '' ? $commit : null,
    'php_built' => PHP_VERSION,
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

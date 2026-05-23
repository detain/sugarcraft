<?php

declare(strict_types=1);

/**
 * Refresh visual regression goldens (vcr_use.md §8 / Section G).
 *
 * Re-renders every .tape in tests/golden/tapes/ with both the PHP and ffmpeg
 * encoders and overwrites tests/golden/<name>.<encoder>.gif. Prints a warning
 * and exits non-zero when more than 3 goldens shift in a single run — guards
 * against accidental mass-overwrite (the user must pass --force to confirm).
 *
 * Usage:
 *   php candy-vcr/scripts/refresh-goldens.php           # safe (warns if >3 change)
 *   php candy-vcr/scripts/refresh-goldens.php --force   # ignore the >3 guard
 *   php candy-vcr/scripts/refresh-goldens.php --dry-run # show diffs, don't write
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "refresh-goldens: cannot locate vendor/autoload.php at {$autoload}\n");
    exit(1);
}
require $autoload;

use SugarCraft\Vcr\Encode\TapeToGif;

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$dryRun = in_array('--dry-run', $args, true);

$goldenDir = realpath(__DIR__ . '/../tests/golden');
if ($goldenDir === false) {
    fwrite(STDERR, "refresh-goldens: tests/golden directory missing\n");
    exit(1);
}
$tapeDir = $goldenDir . '/tapes';
$tapes = glob($tapeDir . '/*.tape') ?: [];
sort($tapes);

if ($tapes === []) {
    fwrite(STDERR, "refresh-goldens: no .tape files in {$tapeDir}\n");
    exit(1);
}

$ffmpegAvailable = shell_exec('command -v ffmpeg 2>/dev/null') !== null;
if (!$ffmpegAvailable) {
    fwrite(STDOUT, "refresh-goldens: ffmpeg not on PATH — skipping ffmpeg-encoder goldens.\n");
}

$encoders = ['php'];
if ($ffmpegAvailable) {
    $encoders[] = 'ffmpeg';
}

$changes = [];
$tmpDir = sys_get_temp_dir() . '/candy-vcr-refresh-' . getmypid();
@mkdir($tmpDir, 0700, true);

foreach ($tapes as $tape) {
    $base = preg_replace('/\.tape$/', '', basename($tape)) ?? basename($tape);
    foreach ($encoders as $encoder) {
        $goldenPath = "{$goldenDir}/{$base}.{$encoder}.gif";
        $producedPath = "{$tmpDir}/{$base}.{$encoder}.gif";

        TapeToGif::create(['encoder' => $encoder])->render($tape, $producedPath, [
            'encoder' => $encoder,
        ]);

        $producedHash = hash_file('sha256', $producedPath);
        $existingHash = is_file($goldenPath) ? hash_file('sha256', $goldenPath) : null;

        if ($existingHash === $producedHash) {
            fwrite(STDOUT, "  unchanged: {$base}.{$encoder}.gif\n");
            continue;
        }

        $changes[] = [
            'base' => $base,
            'encoder' => $encoder,
            'produced' => $producedPath,
            'golden' => $goldenPath,
            'producedHash' => $producedHash,
            'existingHash' => $existingHash,
        ];
        $status = $existingHash === null ? 'NEW' : 'CHANGED';
        fwrite(STDOUT, "  {$status}: {$base}.{$encoder}.gif"
            . ($existingHash !== null ? " ({$existingHash} -> {$producedHash})" : "")
            . "\n");
    }
}

fwrite(STDOUT, "\nrefresh-goldens: " . count($changes) . " golden(s) would change.\n");

if (count($changes) > 3 && !$force && !$dryRun) {
    fwrite(STDERR, "\nrefresh-goldens: WARNING — more than 3 goldens differ.\n");
    fwrite(STDERR, "  Re-run with --force to confirm the mass-overwrite, or --dry-run to inspect.\n");
    exit(2);
}

if ($dryRun) {
    fwrite(STDOUT, "refresh-goldens: --dry-run, no files written.\n");
    cleanupTmp($tmpDir);
    exit(0);
}

foreach ($changes as $change) {
    if (!copy($change['produced'], $change['golden'])) {
        fwrite(STDERR, "refresh-goldens: failed to copy {$change['produced']} → {$change['golden']}\n");
        cleanupTmp($tmpDir);
        exit(1);
    }
}

cleanupTmp($tmpDir);
fwrite(STDOUT, "refresh-goldens: wrote " . count($changes) . " golden(s).\n");
exit(0);

function cleanupTmp(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

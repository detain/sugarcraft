<?php

declare(strict_types=1);

/**
 * generate-goldens.php — Generates golden snapshot files for sugar-dash examples.
 *
 * Walks every examples/*.php, runs it at 80x24 and 120x40 dimensions,
 * and writes the captured stdout to tests/golden/<dim>/<name>.golden.
 *
 * Usage: php tools/generate-goldens.php [--dimensions 80x24,120x40]
 *
 * Environment:
 *   DRY_RUN=1   — print what would be generated without writing files
 *   PHP_VERSION — override php -v output recorded in goldens (default: actual)
 */

require_once __DIR__ . '/../vendor/autoload.php';

/// ------------------------------------------------------------
// Configuration
/// ------------------------------------------------------------

/** @var list<string> Dimensions to generate goldens for (format: WIDTHxHEIGHT) */
$targetDimensions = [
    '80x24'  => [80, 24],
    '120x40' => [120, 40],
];

/**
 * Examples that are interactive (block on user input) and cannot be
 * snapshotted. These get skipped — they'll be covered by VHS recordings
 * in part 2 of this step.
 *
 * @var array<string, string> slug => reason
 */
$interactiveExamples = [
    'dashboard-live'       => 'interactive TUI that blocks on keyboard input',
    'dashboard-accordion-timeline' => 'accordion and timeline demo',
    'dashboard-charts'     => 'interactive dashboard demo',
    'dashboard-complex'    => 'interactive dashboard demo',
    'dashboard-data'        => 'interactive dashboard demo',
    'dashboard-devtools'   => 'interactive dashboard demo',
    'dashboard-form'        => 'interactive dashboard demo',
    'dashboard-layout'     => 'interactive dashboard demo',
    'dashboard-media'      => 'interactive dashboard demo',
    'dashboard-metrics'   => 'interactive dashboard demo',
    'dashboard-nav'        => 'interactive dashboard demo',
    'dashboard-showcase'    => 'interactive dashboard demo',
    'dashboard-status'     => 'interactive dashboard demo',
    'dashboard-text'        => 'interactive dashboard demo',
    'dashboard-time'       => 'interactive dashboard demo',
    'dashboard-ui'          => 'interactive dashboard demo',
    'terminal'            => 'interactive terminal emulator',
    'commandPalette'      => 'interactive command palette (blocks on input)',
    'drawer'             => 'interactive drawer demo',
    'popover'            => 'interactive popover demo (may block on hover/events)',
];

/**
 * Examples that produce non-deterministic output (real-time data,
 * time-dependent rendering, external network calls).
 */
$nonDeterministicExamples = [
    'clock'       => 'real-time clock, output varies every second',
    'timer'       => 'real-time timer, output varies every second',
    'stopwatch'  => 'real-time stopwatch, output varies continuously',
    'spinner'    => 'animated spinner, frame depends on time of run',
    'loadingText' => 'animated loading text, frame depends on time of run',
    'log'        => 'timestamp-based log entries, differ every run',
    'bubble'     => 'speech bubble may include time-dependent content',
    'wttr-in'    => 'network call to wttr.in, output varies by weather',
    'weather'    => 'network call to wttr.in, output varies by weather',
];

/// ------------------------------------------------------------
// Helpers
/// ------------------------------------------------------------

/**
 * Run a PHP example file and capture its stdout.
 *
 * @param string                 $file Absolute path to example file
 * @param array<string,string>    $env  Extra environment variables
 * @return string Output captured from stdout
 */
function runExample(string $file, array $env = []): string
{
    // Merge with current environment so existing vars (PATH, etc.) are preserved
    $fullEnv = array_merge(getenv(), $env);

    $spec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr (merged into stdout)
    ];

    $proc = proc_open('php ' . escapeshellarg($file), $spec, $pipes, null, $fullEnv);

    if ($proc === false) {
        return '';
    }

    // Close stdin immediately (no input needed)
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    proc_close($proc);

    return ($output ?? '') . ($stderr ?? '');
}

/**
 * Check if an example file is interactive (blocks on user input).
 */
function isInteractiveExample(string $slug, string $content): bool
{
    global $interactiveExamples;

    if (isset($interactiveExamples[$slug])) {
        return true;
    }

    // Heuristic: if the file reads from STDIN or waits for keypress
    if (preg_match('/fgets\s*\(\s*STDIN|fgetcsv\s*\(\s*STDIN|readline\(/', $content)) {
        return true;
    }

    // dashboard-*.php files that aren't explicitly listed above are likely interactive
    if (preg_match('/^dashboard-.*\.php$/', basename($slug)) && $slug !== 'dashboard.php') {
        return true;
    }

    return false;
}

/**
 * Check if an example produces non-deterministic output.
 */
function isNonDeterministicExample(string $slug): bool
{
    global $nonDeterministicExamples;

    return isset($nonDeterministicExamples[$slug]);
}

/**
 * Patch example content for running in a temp file.
 *
 * Fixes:
 * 1. setSize dimensions → target width/height
 * 2. require_once path → absolute path (because __DIR__ in /tmp/...
 *    resolves to /tmp, not the sugar-dash root)
 *
 * @param string $content     File content
 * @param int    $width       Target width
 * @param int    $height      Target height
 * @param string $examplesDir Absolute path to examples/ directory
 * @return string Patched content
 */
function patchExample(string $content, int $width, int $height, string $examplesDir): string
{
    // Patch setSize
    $pattern = '/->setSize\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/';
    $patched = "->setSize({$width}, {$height})";
    $content = preg_replace($pattern, $patched, $content, 1);

    // Fix require_once path: the example files use __DIR__ . '/../vendor/autoload.php'
    // When the temp file runs from /tmp, __DIR__ is /tmp, not the sugar-dash root.
    // Replace with the absolute path to the sugar-dash vendor autoloader.
    $sugarDashRoot = realpath($examplesDir . '/..');
    if ($sugarDashRoot !== false) {
        $autoloadAbs = $sugarDashRoot . '/vendor/autoload.php';
        $content = preg_replace(
            "/require_once\s*\(?\s*__DIR__\s*\.\s*['\"][^'\"]*vendor\/autoload\.php['\"]\s*\)?/",
            "require_once '" . addslashes($autoloadAbs) . "'",
            $content
        );
    }

    return $content;
}

/**
 * Write a golden file atomically (write to tmp, then rename).
 */
function writeGolden(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $tmp = $dir . '/.tmp.' . bin2hex(random_bytes(8));
    file_put_contents($tmp, $content);
    rename($tmp, $path);
}

/// ------------------------------------------------------------
// Main
/// ------------------------------------------------------------

$examplesDir = __DIR__ . '/../examples';
$goldenRoot  = __DIR__ . '/../tests/golden';

$dryRun = isset($_ENV['DRY_RUN']) || getenv('DRY_RUN') === '1';
$phpVersion = $_ENV['PHP_VERSION'] ?? trim(shell_exec('php -v | head -1') ?: 'PHP unknown');

$files = glob("{$examplesDir}/*.php");

if ($files === false) {
    fwrite(STDERR, "Failed to glob examples directory: {$examplesDir}\n");
    exit(1);
}

// Filter to only .php files (not directory names with dots)
$files = array_filter($files, 'is_file');

$skipped = [];
$generated = [];

foreach ($files as $file) {
    $basename = basename($file, '.php');
    $slug = $basename;

    // Skip meta files
    if ($slug === 'run-all' || $slug === 'generate-all') {
        continue;
    }

    $content = file_get_contents($file);

    // Skip interactive examples
    if (isInteractiveExample($slug, $content)) {
        $skipped[] = [$slug, 'interactive (VHS in part 2)'];
        continue;
    }

    // Skip non-deterministic examples
    if (isNonDeterministicExample($slug)) {
        $skipped[] = [$slug, 'non-deterministic output'];
        continue;
    }

    // Generate goldens for each target dimension
    foreach ($targetDimensions as $dimLabel => [$targetWidth, $targetHeight]) {
        $patchedContent = patchExample($content, $targetWidth, $targetHeight, $examplesDir);

        // Write patched content to a temp file and run it
        $tmpFile = sys_get_temp_dir() . '/golden_' . $slug . '_' . $dimLabel . '.php';
        $restored = false;

        try {
            file_put_contents($tmpFile, $patchedContent);

            // Set COLUMNS/LINES env vars (used by some examples, especially dashboard-live)
            $env = [
                'COLUMNS' => (string) $targetWidth,
                'LINES'   => (string) $targetHeight,
            ];

            $output = runExample($tmpFile, $env);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        // Prepend header comment
        $goldenContent = "{$phpVersion}\n";
        $goldenContent .= "# dimensions: {$dimLabel} ({$targetWidth}x{$targetHeight})\n";
        $goldenContent .= "# example: {$slug}\n";
        $goldenContent .= "---\n";
        $goldenContent .= $output;

        $goldenPath = "{$goldenRoot}/{$dimLabel}/{$slug}.golden";

        if ($dryRun) {
            echo "DRY RUN: would write {$goldenPath} (" . strlen($goldenContent) . " bytes)\n";
        } else {
            writeGolden($goldenPath, $goldenContent);
            $generated[] = "{$dimLabel}/{$slug}.golden";
        }
    }
}

/// ------------------------------------------------------------
// Report
/// ------------------------------------------------------------

echo "Golden generation complete.\n";
echo "\n";
echo "Generated: " . count($generated) . " goldens\n";
echo "Skipped:   " . count($skipped) . " examples\n";

if ($skipped) {
    echo "\nSkipped examples:\n";
    foreach ($skipped as [$slug, $reason]) {
        echo "  {$slug}: {$reason}\n";
    }
}

if ($dryRun) {
    echo "\nDRY RUN — no files written.\n";
}

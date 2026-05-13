<?php

declare(strict_types=1);

/**
 * Batch runner for sugar-dash component examples.
 *
 * Runs all example PHP files and reports success/failure.
 * Usage: php run-all.php [component-name]
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Get list of example files
$examplesDir = __DIR__;
$files = glob("$examplesDir/*.php");

// Filter out this file and dashboard files
$files = array_filter($files, function ($f) {
    $basename = basename($f);
    return $basename !== 'run-all.php'
        && $basename !== 'generate-all.php'
        && $basename !== 'dashboard.php';
});

$results = [];
$failures = [];
$successes = [];

// If specific component provided, run only that
if (isset($argv[1])) {
    $targetFile = "$examplesDir/{$argv[1]}.php";
    if (file_exists($targetFile)) {
        $files = [$targetFile];
    } else {
        echo "File not found: {$argv[1]}.php\n";
        exit(1);
    }
}

foreach ($files as $file) {
    $basename = basename($file, '.php');
    $startTime = microtime(true);

    ob_start();
    $output = '';
    $exitCode = 0;

    try {
        // Capture output
        ob_start();
        $output = shell_exec("php " . escapeshellarg($file) . " 2>&1");
        $exitCode = 0; // shell_exec returns null on error

        if ($output === null) {
            $output = ob_get_clean();
        } else {
            ob_end_clean();
        }
    } catch (Exception $e) {
        $output = "Exception: " . $e->getMessage();
        $exitCode = 1;
    }

    $duration = microtime(true) - $startTime;

    // Determine success/failure based on output
    $hasAnsiCodes = preg_match('/\x1b\[[0-9;]*[a-zA-Z]/', $output ?? '');
    $hasContent = !empty(trim($output ?? ''));

    if ($hasContent || $hasAnsiCodes) {
        $results[$basename] = [
            'status' => 'OK',
            'duration' => round($duration * 1000, 2) . 'ms',
            'outputLength' => strlen($output ?? ''),
        ];
        $successes[] = $basename;
    } else {
        $results[$basename] = [
            'status' => 'EMPTY',
            'duration' => round($duration * 1000, 2) . 'ms',
            'outputLength' => strlen($output ?? ''),
        ];
        $failures[] = $basename;
    }
}

// Output summary
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║           SugarDash Component Examples - Test Results          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total: " . count($files) . " examples\n";
echo "Passed: " . count($successes) . "\n";
echo "Empty/Issues: " . count($failures) . "\n\n";

if ($failures) {
    echo "═══ Issues ═══\n";
    foreach ($failures as $f) {
        echo "  ⚠ $f (output: {$results[$f]['outputLength']} bytes)\n";
    }
    echo "\n";
}

// List all results
echo "═══ All Results ═══\n";
ksort($results);
foreach ($results as $name => $result) {
    $icon = $result['status'] === 'OK' ? '✓' : '⚠';
    echo sprintf("  %s %-20s %s\n", $icon, $name, $result['duration']);
}

echo "\n";
exit(count($failures) > 0 ? 1 : 0);

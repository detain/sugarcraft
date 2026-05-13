<?php

declare(strict_types=1);

/**
 * Generator script for VHS tape files.
 *
 * Creates .tape files for all component examples and dashboards.
 */

$examplesDir = __DIR__ . '/examples';
$vhsDir = __DIR__ . '/.vhs';

// Get component example files (excluding non-examples)
$componentFiles = glob("$examplesDir/*.php");
$componentFiles = array_filter($componentFiles, function ($f) {
    $basename = basename($f);
    return $basename !== 'run-all.php'
        && $basename !== 'generate-all.php'
        && $basename !== 'dashboard.php';
});

// Get dashboard files
$dashboardFiles = glob("$examplesDir/dashboard-*.php");

$allFiles = array_merge($componentFiles, $dashboardFiles);

foreach ($allFiles as $file) {
    $basename = basename($file, '.php');

    // Convert PascalCase to kebab-case for the output filename
    $kebabName = preg_replace('/(?<!^)[A-Z]/', '-$0', $basename);
    $kebabName = strtolower($kebabName);

    // Handle special cases like aSCIIBanner -> ascii-banner
    if (strpos($kebabName, 'a-s-c-i-i') !== false) {
        $kebabName = 'ascii-banner';
    }
    if (strpos($kebabName, 'c-t-a') !== false) {
        $kebabName = 'cta';
    }
    if (strpos($kebabName, 'q-r') !== false) {
        $kebabName = 'qr-code';
    }
    if (strpos($kebabName, 'n-progress') !== false) {
        $kebabName = 'nprogress';
    }
    if (strpos($kebabName, 'v-stack') !== false) {
        $kebabName = 'vstack';
    }
    if (strpos($kebabName, 'h-stack') !== false) {
        $kebabName = 'hstack';
    }
    if (strpos($kebabName, 'z-stack') !== false) {
        $kebabName = 'zstack';
    }

    $tapeContent = <<<TAPE
Output .vhs/{$kebabName}.gif
Set FontSize 14
Set Width 800
Set Height 400
Set Theme "TokyoNight"
Type "php examples/{$basename}.php"
Enter
Sleep 1s

TAPE;

    $tapeFile = "$vhsDir/{$kebabName}.tape";
    file_put_contents($tapeFile, $tapeContent);
    echo "Created: $tapeFile\n";
}

echo "\nGenerated " . count($allFiles) . " tape files.\n";

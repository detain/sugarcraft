<?php

declare(strict_types=1);

/**
 * Print the health report for a candy-code-web package root.
 *
 *   php examples/diagnose.php            # this checkout (the directory holding src/ and dist/)
 *   php examples/diagnose.php /some/root # any package root
 *
 * Exits 0 when the bundle is intact, 1 when Assets::diagnose() reports an error.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\CodeWeb\Assets;

$root = $argv[1] ?? dirname(__DIR__);
$report = Assets::diagnose($root);

echo 'dist:    ', $report['dist'] ?? '(none)', PHP_EOL;
echo 'version: ', $report['version'] ?? '(none)', PHP_EOL;
echo 'entries: ', count($report['manifest'] ?? []), PHP_EOL;

if ($report['error'] !== null) {
    fwrite(STDERR, $report['error'] . PHP_EOL);
    exit(1);
}

exit(0);

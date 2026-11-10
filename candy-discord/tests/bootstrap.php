<?php

/**
 * Bundle-local test bootstrap — runs OUTSIDE the marketplace path, straight
 * from the repository, so it may reach for the host vendor directly (a
 * relative hop from here: candy-discord/tests → repo root → candy-code).
 * The runtime bundle never takes this hop; its autoload.php knows only its
 * own subtree.
 *
 * The Tests\ subtree lives beside this file, not under src/, so it gets its
 * own PSR-4 map here — the runtime autoload deliberately never sees it.
 */

declare(strict_types=1);

$host = \dirname(__DIR__, 2) . '/candy-code/vendor/autoload.php';
if (!\is_file($host)) {
    fwrite(STDERR, "bundle tests need the candy-code vendor tree present: {$host}\n");
    exit(1);
}
require_once $host;
require_once __DIR__ . '/../src/autoload.php';

\spl_autoload_register(static function (string $class): void {
    $prefix = 'CandyCode\\Bundles\\Discord\\Tests\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $file = __DIR__ . '/' . \str_replace('\\', '/', $relative) . '.php';
    if (\is_file($file)) {
        require_once $file;
    }
});

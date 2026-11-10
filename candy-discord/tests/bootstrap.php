<?php

/**
 * Bundle-local test bootstrap — runs OUTSIDE the marketplace path, straight
 * from the repository, so it may reach for the host vendor directly (a
 * relative hop from here: candy-discord/tests → repo root → candy-code).
 * The runtime bundle never takes this hop; its autoload.php knows only its
 * own subtree.
 */

declare(strict_types=1);

$host = \dirname(__DIR__, 2) . '/candy-code/vendor/autoload.php';
if (!\is_file($host)) {
    fwrite(STDERR, "bundle tests need the candy-code vendor tree present: {$host}\n");
    exit(1);
}
require_once $host;
require_once __DIR__ . '/../src/autoload.php';

<?php

/**
 * Bundle-local test bootstrap. The suite runs under the bundle's own vendor
 * tree (PL-1c-i): the plugin libraries and the candy-code Lang catalog come
 * from there, so no host vendor hop is taken. The runtime bundle's
 * autoload.php knows only its own subtree.
 *
 * The Tests\ subtree lives beside this file, not under src/, so it gets its
 * own PSR-4 map here — the runtime autoload deliberately never sees it.
 */

declare(strict_types=1);

$host = \dirname(__DIR__) . '/vendor/autoload.php';
if (!\is_file($host)) {
    fwrite(STDERR, "bundle tests need the bundle vendor tree present (composer install): {$host}\n");
    exit(1);
}
require_once $host;
require_once __DIR__ . '/../src/autoload.php';

// msteams-27: suites here bound waits with timers on the loop, so the
// stable-clock pin is loaded FIRST when the host tree ships sugar-testing
// (under ext-uv deadlines are computed against a clock refreshed once per
// loop iteration — a timer armed after synchronous idle is already
// overdue). Conditional by design: the bundle vendor subtree never sees
// sugar-testing, and the host vendor may or may not carry it; StreamSelect
// hosts are already honest. The bundle's own vendor tree is the only
// autoload taken here.
if (\class_exists(\SugarCraft\Testing\LoopPin::class)) {
    \SugarCraft\Testing\LoopPin::pinStableClock();
}

\spl_autoload_register(static function (string $class): void {
    $prefix = 'CandyCode\\Bundles\\MsTeams\\Tests\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $file = __DIR__ . '/' . \str_replace('\\', '/', $relative) . '.php';
    if (\is_file($file)) {
        require_once $file;
    }
});

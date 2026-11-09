<?php

/**
 * Bootstrap for the channel bridge: the product's vendor tree first (the
 * bridge is an external consumer of `candy-code serve` exactly like
 * `candy-code attach`, so ReactPHP, Pawl, rfc6455, and the reusable
 * Server/Context classes come from candy-code's own vendor), then a tiny
 * PSR-4 loader for ChannelBridge\ → this src/ directory.
 *
 * This file is the ONLY place the bridge reaches outside its directory.
 */

declare(strict_types=1);

$candyVendor = \dirname(__DIR__, 3) . '/candy-code/vendor/autoload.php';
if (!\is_readable($candyVendor)) {
    \fwrite(\STDERR, "channel-bridge: candy-code/vendor/autoload.php not found at {$candyVendor}\n");
    \fwrite(\STDERR, "channel-bridge: run `php scripts/refresh-deps.php --mode=linked` from the repo root first.\n");
    exit(1);
}
require $candyVendor;

\spl_autoload_register(static function (string $class): void {
    if (!\str_starts_with($class, 'ChannelBridge\\')) {
        return;
    }
    $relative = \str_replace('\\', '/', \substr($class, \strlen('ChannelBridge\\')));
    $file = __DIR__ . '/' . $relative . '.php';
    if (\is_file($file)) {
        require $file;
    }
});

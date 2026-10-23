<?php

declare(strict_types=1);

/**
 * SugarLog demo — run with: php examples/demo.php
 *
 * Exercises all log levels, structured fields, sub-loggers,
 * multiple formatters, and the stdlog adapter.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\StandardLogAdapter;

// A dedicated stdout stream so every section below lands in one capture.
$out = fopen('php://stdout', 'w');

echo "=== Text (default) logger ===\n";
$log = Logger::new(prefix: '🍪');
$log->info('Starting the oven', ['degree' => 375, 'mode' => 'convection']);
$log->warn('Door left open', ['seconds' => 3]);
$log->error('Temperature sensor disconnected', ['sensor' => 'temp_01']);

echo "\n=== Debug level ===\n";
$debug = Logger::new(level: Level::Debug, prefix: 'DBG');
$debug->debug('Entering bake cycle', ['batch' => 1, 'butter' => true]);
$debug->info('Debug is visible now');

echo "\n=== JSON formatter ===\n";
$json = Logger::new(formatter: new JsonFormatter(), prefix: 'app', stream: $out);
$json->info('Bake complete', ['batch' => 3, 'cookies' => 24]);

echo "\n=== Logfmt formatter ===\n";
$logfmt = Logger::new(formatter: new LogfmtFormatter(), prefix: 'baker', stream: $out);
$logfmt->warn('Low flour', ['brand' => 'King Arthur']);

echo "\n=== Sub-logger (child logger with persistent fields) ===\n";
$parent = Logger::new(prefix: 'oven', stream: $out);
$child = $parent->with(['user' => 'chef', 'session' => 'am-batch']);
$child->info('Preheating');
$child->info('Adding mix-ins', ['chocolate' => true, 'nuts' => false]);

echo "\n=== StandardLogAdapter (net/http *log.Logger compat) ===\n";
// The adapter forces every line to the constructor's level (Error here),
// so even its print() renders with the ERR label.
$stdAdapter = new StandardLogAdapter(Logger::new(prefix: 'http', stream: $out), Level::Error);
$stdAdapter->print('GET /bake 200 12ms');

// The Fatal level throws a RuntimeException right after writing — in Go
// log.Fatal exits the process; the port surfaces that stop as an exception
// so callers can decide. Catch it to keep this demo running to the end.
try {
    $stdAdapter->fatal('listen tcp :8080: bind: address already in use');
} catch (RuntimeException $e) {
    echo "(fatal contract: {$e->getMessage()})\n";
}

echo "\nDone.\n";

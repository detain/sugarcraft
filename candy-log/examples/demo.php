<?php

declare(strict_types=1);

/**
 * CandyLog demo — run with: php examples/demo.php
 *
 * Exercises all log levels, structured fields, sub-loggers,
 * multiple formatters, and the stdlog adapter.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Log\Logger;
use CandyCore\Log\Level;
use CandyCore\Log\Formatter\JsonFormatter;
use CandyCore\Log\Formatter\LogfmtFormatter;
use CandyCore\Log\StandardLogAdapter;

// Capture output for display
$stderr = fopen('php://stdout', 'w');

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
$json = Logger::new(formatter: new JsonFormatter(), prefix: 'app', stream: $stderr);
$json->info('Bake complete', ['batch' => 3, 'cookies' => 24]);

echo "\n=== Logfmt formatter ===\n";
$logfmt = Logger::new(formatter: new LogfmtFormatter(), prefix: 'baker', stream: $stderr);
$logfmt->warn('Low flour', ['brand' => 'King Arthur']);

echo "\n=== Sub-logger (child logger with persistent fields) ===\n";
$parent = Logger::new(prefix: 'oven', stream: $stderr);
$child = $parent->with(['user' => 'chef', 'session' => 'am-batch']);
$child->info('Preheating');
$child->info('Adding mix-ins', ['chocolate' => true, 'nuts' => false]);

echo "\n=== StandardLogAdapter (net/http *log.Logger compat) ===\n";
$stdLogger = new StandardLogAdapter(Logger::new(prefix: 'http'), Level::Error);
$stdAdapter = $stdLogger;
$stdAdapter->print('GET /bake 200 12ms'); // note: print uses info level
$stdAdapter->fatal('listen tcp :8080: bind: address already in use');

echo "\nDone.\n";

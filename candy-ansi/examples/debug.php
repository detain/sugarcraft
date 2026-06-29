<?php

declare(strict_types=1);

/**
 * Debug parser demo.
 *
 * Feeds an ANSI byte sequence through the parser with a DebugHandler
 * and prints the resulting action log.
 *
 * Run: php examples/debug.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Parser;

$handler = new DebugHandler();
$parser = new Parser($handler);

$parser->parseComplete("hello\x1b[31mworld\x1b[0m");

echo "Parsed actions:\n";
print_r($handler->log);

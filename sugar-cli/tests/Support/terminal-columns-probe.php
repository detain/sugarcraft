<?php

declare(strict_types=1);

/**
 * Child process for TerminalTest: runs under a pseudo-terminal, so STDOUT is
 * a real tty. Prints what Terminal::columns() answers for STDOUT and for a
 * memory stream, as JSON.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Cli\Terminal;

echo json_encode(['stdout' => Terminal::columns(), 'memory' => Terminal::columns(fopen('php://memory', 'r+'))]);

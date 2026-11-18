<?php

declare(strict_types=1);

/**
 * Child process for DumbTerminalVetoTest: runs under a pseudo-terminal, so
 * STDOUT is a real tty and Theme::detect() takes its tty path. Prints one
 * no-theme presenter line and nothing else.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Cli\StatusLine;

echo StatusLine::success('done');

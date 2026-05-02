<?php

declare(strict_types=1);

/**
 * FilePicker — render the picker over a known directory at
 * different cursor positions, with and without hidden-file display.
 *
 *   php examples/file-picker.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\FilePicker\FilePicker;

$dir = __DIR__ . '/..';

$default = FilePicker::new(cwd: $dir, height: 8);
echo "\x1b[36mDefault — hidden files filtered\x1b[0m\n";
echo $default->view() . "\n\n";

echo "\x1b[36mShow hidden files\x1b[0m\n";
echo $default->withShowHidden(true)->view() . "\n\n";

echo "\x1b[36mShow icons + size column\x1b[0m\n";
echo $default->withShowIcons(true)->withShowSize(true)->view() . "\n";

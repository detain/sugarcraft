<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlinePaintDefaultTest: drives two
 * successive run() calls over a piped stdin with the output argument
 * OMITTED (mode 'omitted') or EXPLICIT NULL (mode 'null'), so the parent
 * can tell paint-by-default from headless driving by the bytes the child
 * writes. The exit-7 idle sleeper mirrors ReadlinePumpReuseTest: a loop
 * left waiting on an empty-but-open pump fails fast and visibly.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$mode = $argv[1] ?? 'omitted';

$readline = new Readline(new StreamInputDriver(STDIN), static function (): never {
    echo "SPUN\n";
    exit(7);
});

$first  = $mode === 'null'
    ? $readline->run(TextPrompt::new('a: '), null)
    : $readline->run(TextPrompt::new('a: '));
$second = $mode === 'null'
    ? $readline->run(TextPrompt::new('b: '), null)
    : $readline->run(TextPrompt::new('b: '));

echo 'DONE|' . $first->value . '|' . $second->value . "\n";

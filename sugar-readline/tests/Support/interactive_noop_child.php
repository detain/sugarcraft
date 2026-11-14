<?php

declare(strict_types=1);

/**
 * Child fixture for ReadlineInteractiveTest: interactive() over a NON-TTY
 * stdin (piped) must never shell out — the isatty guard leaves the static
 * raw-mode state untouched and the run completes through the injected
 * driver like any plain run().
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($pair[1], "hi\r");

$driver = new StreamInputDriver($pair[0]);
$prompt = Readline::interactive(new TextPrompt('> '), null, new Readline($driver));

$owned = (new ReflectionClass(Readline::class))->getStaticPropertyValue('rawOwned');

echo 'OWNED=' . var_export($owned, true)
    . ' SUBMITTED=' . var_export($prompt->isSubmitted(), true)
    . ' VALUE=' . var_export($prompt->value(), true) . "\n";

<?php

declare(strict_types=1);

/**
 * endpoint-argv — print the ssh argv the launcher would hand to pcntl_exec
 * for each endpoint in examples/sample-wishlist.yml.
 *
 *   php examples/endpoint-argv.php
 *
 * Nothing is executed and no terminal is needed. The first endpoint's identity
 * file is expanded against $HOME, so that line differs between machines.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\SshLauncher\Config;

foreach (Config::load(__DIR__ . '/sample-wishlist.yml') as $endpoint) {
    echo $endpoint->name, "\n";
    echo '  ', implode(' ', $endpoint->toSshArgv('ssh')), "\n";
}

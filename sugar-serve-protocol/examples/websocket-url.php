<?php

declare(strict_types=1);

/**
 * Normalises server addresses to the candy-code.v1 WebSocket URL.
 *
 * Usage: php examples/websocket-url.php
 *
 * Prints the dial URL for each address, or "refused" where WebsocketUrl::from()
 * returns null. The refused rows are the malformed inputs the package rejects on
 * purpose, so a client never dials a host it cannot reach.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\ServeProtocol\Protocol;
use SugarCraft\ServeProtocol\Url\WebsocketUrl;

$addresses = [
    'http://127.0.0.1:7420',
    'https://example.test',
    'ws://[::1]:7420',
    'http://[fe80::1%25eth0]:8080',
    'http://[abc]:8080',
    'http://::1',
    'ftp://127.0.0.1',
];

echo 'subprotocol: ', Protocol::SUBPROTOCOL, PHP_EOL;
foreach ($addresses as $address) {
    $url = WebsocketUrl::from($address);
    printf("%-30s %s\n", $address, $url ?? 'refused');
}

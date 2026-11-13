<?php

/**
 * Loopback router for tests/HttpFetchTest.php — the ONLY server candy-mdview's
 * fetch tests talk to (zero outbound connections). Started with
 * `php -S 127.0.0.1:<port> <this file>`; every response is generated, so no
 * docroot files exist.
 */

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

switch ($path) {
    case '/ping':
        echo 'pong';
        break;

    case '/x.md':
        header('Content-Type: text/markdown');
        echo "# Fetched Doc\n\nremote body\n";
        break;

    case '/empty':
        http_response_code(200);
        break;

    case '/bin.md':
        header('Content-Type: text/markdown');
        echo "# ok\n\x00\x01\x02raw control tail\n";
        break;

    case '/huge.md':
        header('Content-Type: text/markdown');
        // DocumentGate::MAX_INPUT_BYTES is 64 MiB — one byte more tips the
        // capped read into its named refusal. The client stops at cap+1
        // bytes and hangs up; the warning from the aborted write below is
        // the server noticing, not a failure of the door.
        echo str_repeat('x', 64 * 1024 * 1024 + 1);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        echo 'round and round';
        break;

    case '/gone':
        http_response_code(404);
        echo 'nope';
        break;

    default:
        http_response_code(404);
        echo 'gone';
}

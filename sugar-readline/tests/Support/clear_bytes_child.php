<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineClearBytesTest (S-63, finding 098):
 * drives one TextPrompt over an internal socket-pair feed while painting to
 * STDOUT — the parent runs this under script(1) so STDOUT really is a tty
 * and the S-23 capability classification engages. The TERM value arrives
 * from the parent's command line, letting the test observe the two clear
 * arms the in-process socket capture cannot: the tty whole-scene clear
 * (CSI 2J + home) and the dumb-terminal silence.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$keys = $argv[1] ?? "x\x0c\r";

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    fwrite(STDERR, "socketpair failed\n");
    exit(3);
}
[$write, $read] = $pair;
fwrite($write, $keys);
fclose($write);

$result = (new Readline(new StreamInputDriver($read), static function (): never {
    echo "SPUN\n";
    exit(7);
}))->run(TextPrompt::new('> '));

fclose($read);
echo 'DONE|' . $result->value . "\n";

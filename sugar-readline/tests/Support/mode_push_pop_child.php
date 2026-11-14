<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineModePushPopTest (S-64, finding 099):
 * drives one run() while painting to STDOUT — the parent runs this under
 * script(1) so STDOUT really is a tty and the DEC-private mode pushes
 * engage (bracketed paste `CSI ?2004h` + kitty disambiguate `CSI >1u`).
 * The transcript then carries the whole push/repaint/pop sequence the
 * in-process socket capture deliberately never sees.
 *
 * Modes:
 *   clean  — a real TextPrompt submits; finally pops both layers.
 *   throw  — the prompt's handleKey throws mid-loop; the pop pair must
 *            still reach the transcript (run()'s finally on the
 *            exception path).
 *   dumb   — same as clean; the parent sets TERM=dumb so neither the
 *            pushes nor the pops may be written (S-23 capability gate).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$mode = $argv[1] ?? 'clean';
$keys = $argv[2] ?? "ab\r";

/**
 * Minimal PromptInterface shape whose every key throws, to force the
 * exception path out of runLoop and through run()'s finally.
 */
final class ThrowingModePrompt
{
    public function view(): string
    {
        return '> throw';
    }

    public function handleKey(string $key): self
    {
        throw new \RuntimeException('boom-mid-loop');
    }

    public function isSubmitted(): bool
    {
        return false;
    }

    public function isAborted(): bool
    {
        return false;
    }
}

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    fwrite(STDERR, "socketpair failed\n");
    exit(3);
}
[$write, $read] = $pair;
fwrite($write, $keys);
fclose($write);

$prompt = $mode === 'throw' ? new ThrowingModePrompt() : TextPrompt::new('> ');

try {
    $result = (new Readline(new StreamInputDriver($read), static function (): never {
        echo "SPUN\n";
        exit(7);
    }))->run($prompt, STDOUT);
    fclose($read);
    echo 'DONE|' . $result->value . "\n";
} catch (\RuntimeException $exception) {
    fclose($read);
    echo 'THREW|' . $exception->getMessage() . "\n";
}

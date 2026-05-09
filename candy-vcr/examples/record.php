<?php

declare(strict_types=1);

/**
 * Record a tiny counter program into a cassette file.
 *
 * Usage:
 *   php examples/record.php /tmp/session.cas
 *
 * The recorded cassette can then be inspected with:
 *   bin/candy-vcr inspect /tmp/session.cas
 * or replayed back to stdout:
 *   bin/candy-vcr replay /tmp/session.cas --speed=realtime
 */

require_once __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Vcr\Recorder;

$path = $argv[1] ?? __DIR__ . '/cassettes/recorded.cas';

final class CounterModel implements Model
{
    public function __construct(public readonly int $count = 0) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [new self($this->count + 1), null];
    }

    public function view(): string
    {
        return "counter: {$this->count}\n  press any key, q to quit\n";
    }
}

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
[$inputRead, $inputWrite] = $sockets;
$output = fopen('php://memory', 'w+');
$loop = new StreamSelectLoop();

$program = new Program(
    new CounterModel(),
    new ProgramOptions(
        useAltScreen: false,
        catchInterrupts: false,
        hideCursor: false,
        framerate: 1000.0,
        input: $inputRead,
        output: $output,
        loop: $loop,
    ),
);
$program->withRecorder(Recorder::open($path));

// Pipe a few input bytes so the recording captures real Msg flow.
$loop->futureTick(static function () use ($inputWrite): void {
    fwrite($inputWrite, "abc");
});
$loop->addTimer(0.030, static fn() => $program->quit());
$loop->addTimer(2.0, static fn() => $loop->stop());
$program->run();

fclose($inputWrite);
fclose($inputRead);
fclose($output);

echo "recorded cassette: {$path}\n";
echo "inspect:  bin/candy-vcr inspect {$path}\n";
echo "replay:   bin/candy-vcr replay  {$path} --speed=realtime\n";

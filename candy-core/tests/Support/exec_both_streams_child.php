<?php

declare(strict_types=1);

/*
 * Probe child for ProgramExecCaptureTest: runs a real Program whose init
 * Cmd execs a grandchild that overflows BOTH kernel capture pipes (64 KiB
 * each), then reports what came back on its own stderr.
 *
 * Exit 0 + "PROBE-OK" only when both captured streams arrived complete.
 * Against a sequential stdout-then-stderr drain this child HANGS inside
 * runExec — the parent test bounds the wait and fails loudly.
 */

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExecMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;

require __DIR__ . '/../../vendor/autoload.php';

const PAYLOAD_BYTES = 200_000;

final class ExecProbeModel implements Model
{
    use SubscriptionCapable;

    public function __construct(
        public readonly int $seen = 0,
        public readonly ?ExecMsg $exec = null,
    ) {
    }

    public function init(): ?\Closure
    {
        $code = 'fwrite(STDOUT, str_repeat("a", ' . PAYLOAD_BYTES . ')); fflush(STDOUT);'
            . ' fwrite(STDERR, str_repeat("e", ' . PAYLOAD_BYTES . ')); fflush(STDERR);';

        return Cmd::exec([PHP_BINARY, '-r', $code], captureOutput: true);
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        $exec = $msg instanceof ExecMsg ? $msg : $this->exec;

        return [new self($this->seen + 1, $exec), $msg instanceof ExecMsg ? Cmd::quit() : null];
    }

    public function view(): string
    {
        return 'probe: ' . $this->seen;
    }
}

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($sockets === false) {
    fwrite(STDERR, "PROBE-FAIL socketpair\n");
    exit(2);
}
[$in, $writer] = $sockets;
$out = fopen('php://memory', 'w+');
if ($out === false) {
    fwrite(STDERR, "PROBE-FAIL memory\n");
    exit(2);
}

$loop = new \React\EventLoop\StreamSelectLoop();
$program = new Program(
    new ExecProbeModel(),
    new ProgramOptions(
        useAltScreen: false,
        catchInterrupts: false,
        hideCursor: false,
        input: $in,
        output: $out,
        loop: $loop,
    ),
);

// Wall-clock leash for unrelated hangs on the async side; the deadlock this
// probe hunts lives inside a blocking call, so the bounding is the parent's
// job (see ProgramExecCaptureTest).
$loop->addTimer(15.0, static fn () => $loop->stop());

$final = $program->run();
$exec = $final instanceof ExecProbeModel ? $final->exec : null;

if ($exec === null) {
    fwrite(STDERR, "PROBE-FAIL no ExecMsg arrived\n");
    exit(1);
}

$stdoutLen = strlen($exec->stdout);
$stderrLen = strlen($exec->stderr);
$ok = $exec->error === null
    && $exec->exitCode === 0
    && $stdoutLen === PAYLOAD_BYTES
    && $stderrLen === PAYLOAD_BYTES;

fwrite(
    STDERR,
    sprintf(
        "PROBE-%s stdout=%d stderr=%d exit=%d error=%s\n",
        $ok ? 'OK' : 'FAIL',
        $stdoutLen,
        $stderrLen,
        $exec->exitCode,
        $exec->error !== null ? $exec->error->getMessage() : 'none',
    ),
);
exit($ok ? 0 : 1);

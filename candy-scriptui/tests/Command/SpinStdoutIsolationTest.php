<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Tester\CommandTester;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process;

/**
 * scriptui-09 (candy-scriptui-053): the spinner arm must not leak the
 * child's stdout onto OUR stdout without --show-output. Before the fix
 * the child inherited our descriptors, so `spin -- child > file` wrote
 * the child's bytes into the file even though nothing was "shown" —
 * the option help and gum's default-silent posture imply the opposite.
 *
 * The subprocess legs need a controlling terminal (without one the
 * command honestly takes the headless passthrough arm, which these
 * pins must NOT disturb); hosts lacking one self-skip, and the source
 * pin keeps the cure greppable there.
 */
final class SpinStdoutIsolationTest extends TestCase
{
    private const BIN      = __DIR__ . '/../../bin/candy-scriptui';
    private const DEADLINE = 40.0;

    public function testSpinnerArmCapturesChildStdoutSilently(): void
    {
        [$rc, $stdout] = $this->runSpinnerBin(['--', '/bin/sh', '-c', 'printf LEAKED-7c2']);

        self::assertSame(0, $rc);
        self::assertSame('', $stdout, 'the child stdout must never touch the data channel without --show-output');
    }

    public function testShowOutputReplaysChildBytesToStdout(): void
    {
        [$rc, $stdout] = $this->runSpinnerBin(['--show-output', '--', '/bin/sh', '-c', 'printf CHILD-OK']);

        self::assertSame(0, $rc);
        self::assertSame('CHILD-OK', $stdout, '--show-output must replay the capture verbatim');
    }

    public function testChildStderrIsSilentByDefaultButPrintedOnErrorFlag(): void
    {
        [$rc, $stdout, $stderr] = $this->runSpinnerBin(['--', '/bin/sh', '-c', 'printf ERR-MARK >&2']);

        self::assertSame(0, $rc);
        self::assertSame('', $stdout, 'child stderr must not cross into the data channel either');
        self::assertStringNotContainsString('ERR-MARK', $stderr, 'the spinner arm captures child stderr; the frames themselves may paint here');

        [$rc, $stdout, $stderr] = $this->runSpinnerBin(['--show-error', '--', '/bin/sh', '-c', 'printf ERR-MARK >&2']);

        self::assertSame(0, $rc);
        self::assertStringContainsString('ERR-MARK', $stderr, '--show-error must surface the captured child stderr');
    }

    public function testChildExitStatusPassesThroughTheSpinnerArm(): void
    {
        [$rc, $stdout] = $this->runSpinnerBin(['--', '/bin/sh', '-c', 'printf SEVEN; exit 7']);

        self::assertSame(7, $rc, 'default-silent capture must not change the child verdict');
        self::assertSame('', $stdout);
    }

    public function testCapturePostureSourcePins(): void
    {
        // scriptui-53 KEEP (cross-platform pin): the spinner-arm behaviour
        // legs above self-skip when the host has no controlling terminal, so
        // the forced-capture posture and its option-help promise stay
        // greppable. The capture behaviour itself is proven by the
        // runSpinnerBin and FakeProcess legs above.
        $cmd = (string) \file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');

        self::assertStringContainsString('$headless ? $showOutput : true', $cmd, 'the spinner arm must force captureStdout');
        self::assertStringContainsString('$headless ? $showError : true', $cmd, 'the spinner arm must force captureStderr');
        self::assertStringContainsString('captured silently by default', $cmd, 'the option help must state the posture');
    }

    public function testSpinnerArmRequestsCaptureEvenWithoutShowFlags(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('stream_socket_pair(STREAM_PF_UNIX, …) is not supported on Windows.');
        }

        $fake = new FakeProcess();
        $fake->exitCode = 0; // settle on the first tick

        $streams = [];
        $command = new SpinCommand(
            processFactory: static function (array $argv, bool $so, bool $se) use ($fake): Process {
                $fake->recordSpawn($argv, $so, $se);
                return $fake;
            },
            programFactory: static function (Model $model, LoopInterface $loop) use (&$streams): Program {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                \assert($pair !== false);
                [$reader, $writer] = $pair;
                $out = \fopen('php://memory', 'w+');
                $streams[] = $reader;
                $streams[] = $writer;
                $streams[] = $out;

                return new Program($model, new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      false,
                    catchInterrupts: false,
                    input:           $reader,
                    output:          $out,
                    loop:            $loop,
                    windowSize:      ['cols' => 80, 'rows' => 24],
                ));
            },
        );

        try {
            $tester = new CommandTester($command);
            $rc     = $tester->execute(['argv' => ['/bin/true']]);

            self::assertSame(0, $rc);
            self::assertTrue($fake->spawnCaptureStdout, 'spinner arm must capture stdout without --show-output (candy-scriptui-053)');
            self::assertTrue($fake->spawnCaptureStderr, 'spinner arm must capture stderr without --show-error');
        } finally {
            foreach ($streams as $stream) {
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
            }
        }
    }

    /**
     * Run the shipped bin WITHOUT forcing headless: on a host with a
     * controlling terminal the command takes the spinner arm (frames to
     * /dev/tty-backed paint stream or stderr, stdout left pure).
     *
     * @param list<string> $spinArgs
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    private function runSpinnerBin(array $spinArgs): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX-only: the controlling-terminal probe and /bin/sh legs');
        }
        $tty = @\fopen('/dev/tty', 'r+');
        if ($tty === false) {
            self::markTestSkipped('host has no controlling terminal — the spinner arm is unreachable here (the source pins above hold the cure)');
        }
        \fclose($tty);

        $pipes = [];
        $proc  = @\proc_open(
            [PHP_BINARY, self::BIN, 'spin', ...$spinArgs],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) \getenv('PATH'), 'HOME' => (string) \getenv('HOME')],
        );
        self::assertIsResource($proc, 'spinner-arm child must start');

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = $stderr = '';
        $end    = \microtime(true) + self::DEADLINE;
        while (\microtime(true) < $end) {
            $stdout .= (string) \fread($pipes[1], 65536);
            $stderr .= (string) \fread($pipes[2], 65536);
            if (\feof($pipes[1]) && \feof($pipes[2])) {
                break;
            }
            \usleep(10_000);
        }
        if (!\feof($pipes[1]) || !\feof($pipes[2]) || \proc_get_status($proc)['running']) {
            \proc_terminate($proc, 9);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);
            self::fail("spinner-arm spin did not settle within " . self::DEADLINE . "s\nstdout: $stdout\nstderr: $stderr");
        }
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $stdout, $stderr];
    }
}

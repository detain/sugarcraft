<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Tools\Sandbox\EgressBridge;

/**
 * C-TOOL-4.f: the in-jail bridge. The design law is the ordering — bind
 * 127.0.0.1:<port> FIRST so the sandboxed command's very first connect
 * cannot race the listener — and bytes written before the unix dial lands
 * are buffered and flushed in order, never lost or doubled.
 *
 * The fork/exec leg runs in a php SUBPROCESS (never inside the suite), so
 * no test process here forks without the reaper's ledger; the subprocess
 * itself exits through the bridge's own waitpid path.
 */
final class EgressBridgeTest extends TestCase
{
    public function testBindReportsAnEphemeralLoopbackPortOnAReadyListener(): void
    {
        $loop = new StreamSelectLoop();
        $listener = EgressBridge::bind(0, $loop);
        $address = (string) $listener->getAddress();
        self::assertStringStartsWith('tcp://127.0.0.1:', $address);
        self::assertGreaterThan(0, $this->egressPort($address));

        // "Ready" means the KERNEL accepts before the loop ever runs: a
        // blocking connect at this instant succeeds because bind() already
        // happened — exactly the race the ordering law removes for the
        // sandboxed command's first request.
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client('tcp://' . substr($address, 6), $errno, $errstr, 1.0);
        self::assertIsResource($client, 'connect must succeed before the loop runs: ' . $errstr);
        fclose($client);
        $listener->close();
    }

    public function testEarlyBytesArriveAtTheUnixTargetInOrderAndExactlyOnce(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->bridgeTempDirectory();
        $unixPath = $directory . '/u';
        $collected = '';
        $echoServer = new SocketServer('unix://' . $unixPath, [], $loop);
        $echoServer->on('connection', static function (ConnectionInterface $conn) use (&$collected): void {
            $conn->on('data', static function (string $chunk) use ($conn, &$collected): void {
                $collected .= $chunk;
                $conn->write($chunk);
            });
        });

        $listener = EgressBridge::bind(0, $loop);
        EgressBridge::relay($listener, $unixPath, $loop);

        // The command connects and pipelines its first write BEFORE the
        // loop has served a single tick — the buffered leg.
        $client = $this->blockingClient($this->egressPort((string) $listener->getAddress()));
        fwrite($client, 'one');
        // ... and a second write on a later tick — the direct-forward leg.
        $loop->addTimer(0.03, static function () use ($client): void {
            fwrite($client, 'two');
        });

        $received = '';
        stream_set_blocking($client, false);
        // addReadStream() returns void in react/event-loop 1.x — the watcher
        // is dropped with removeReadStream(), there is no TimerInterface here.
        $loop->addReadStream($client, static function ($stream) use ($loop, &$received): void {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    $loop->removeReadStream($stream);
                    $loop->stop();
                }
                return;
            }
            $received .= $chunk;
            if ($received === 'onetwo') {
                $loop->removeReadStream($stream);
                $loop->stop();
            }
        });
        $loop->addTimer(0.9, static fn () => $loop->stop());
        $loop->run();

        self::assertSame('onetwo', $received, 'echo round-trip must be in order with no dupes and no loss');
        self::assertSame('onetwo', $collected);
        fclose($client);
        $echoServer->close();
        $listener->close();
        @unlink($unixPath);
        @rmdir($directory);
    }

    public function testADeadUnixTargetClosesTheClientStream(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->bridgeTempDirectory();
        $listener = EgressBridge::bind(0, $loop);
        EgressBridge::relay($listener, $directory . '/never-created', $loop);

        $client = $this->blockingClient($this->egressPort((string) $listener->getAddress()));
        fwrite($client, 'hello!');
        stream_set_blocking($client, false);
        $sawEof = false;
        $loop->addReadStream($client, static function ($stream) use ($loop, &$sawEof): void {
            $chunk = fread($stream, 64);
            if ($chunk === false || feof($stream)) {
                $sawEof = true;
                $loop->removeReadStream($stream);
                $loop->stop();
            }
        });
        $loop->addTimer(0.9, static fn () => $loop->stop());
        $loop->run();

        self::assertTrue($sawEof, 'a bridge that cannot reach its proxy must end the command-side stream, not hang');
        fclose($client);
        $listener->close();
        @rmdir($directory);
    }

    public function testRunExecutesTheCommandAndPropagatesItsExitStatus(): void
    {
        [$out, $code] = $this->bridgeSubprocess(PHP_BINARY, 'exit(7);');
        self::assertSame(0, $code, 'the bridge subprocess itself must end cleanly: ' . $out);
        self::assertSame('rc=7', $out, 'the command exit status rides run() verbatim');
    }

    public function testRunReportsTheHouseSignalDeathCodeWhenExecFails(): void
    {
        // pcntl_exec of a nonexistent program cannot replace the child, so
        // ForkedChild::exitNow(127) runs — and its documented first act is
        // a self-SIGKILL, which the parent observes as signal death and
        // maps to 128+9. 137 is therefore the honest exec-failure status
        // for this bridge; the design's "127" is the exitNow argument.
        [$out, $code] = $this->bridgeSubprocess('/nonexistent/cc-tool-4f-target', 'exit(0);');
        self::assertSame(0, $code);
        self::assertSame('rc=137', $out);
    }

    /**
     * @return array{0: string, 1: int} subprocess stdout and its exit code
     */
    private function bridgeSubprocess(string $program, string $command): array
    {
        $directory = $this->bridgeTempDirectory();
        $script = $directory . '/bridge-child.php';
        $autoload = str_replace('\\', '\\\\', BackgroundSupervisor::autoloadPath());
        $unixPath = str_replace('\\', '\\\\', $directory . '/unused-u');
        $programLiteral = str_replace('\\', '\\\\', $program);
        $codeLiteral = str_replace(['\\', "'"], ['\\\\', "\\'"], $command);
        file_put_contents($script, <<<PHP
            <?php
            declare(strict_types=1);
            require '{$autoload}';
            \$loop = new React\EventLoop\StreamSelectLoop();
            \$rc = SugarCraft\Code\Tools\Sandbox\EgressBridge::run('{$unixPath}', '{$programLiteral}', ['-r', '{$codeLiteral}'], 0, \$loop);
            fwrite(STDOUT, 'rc=' . \$rc);

            PHP);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $this->noiseFilter($err), 'the bridge subprocess must stay quiet on stderr');

        @unlink($script);
        @rmdir($directory);
        return [$out, $status];
    }

    private function noiseFilter(string $stderr): string
    {
        // Xdebug/FFI probes can print banners on stderr in spawned children;
        // only lines naming our bridge are failures.
        $kept = [];
        foreach (explode("\n", $stderr) as $line) {
            if ($line !== '' && stripos($line, 'egress') !== false) {
                $kept[] = $line;
            }
        }
        return implode("\n", $kept);
    }

    private function blockingClient(int $port)
    {
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
        self::assertIsResource($client, 'bridge-side connect failed: ' . $errstr);
        return $client;
    }

    private function egressPort(string $address): int
    {
        $colon = strrpos($address, ':');
        self::assertIsInt($colon);
        return (int) substr($address, $colon + 1);
    }

    private function bridgeTempDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $base . '/ccbt' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (@mkdir($candidate, 0700) && is_dir($candidate)) {
                return $candidate;
            }
        }
        self::fail('could not create a private temp directory');
    }
}

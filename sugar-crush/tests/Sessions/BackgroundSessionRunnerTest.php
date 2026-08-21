<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;

/**
 * Covers the agent loop a `/bg` session actually runs.
 *
 * The pre-W3 daemon only answered HEARTBEAT/RESUME/STOP, so
 * `Backgrounded as <id>` described work that never happened; every
 * executeTask() assertion here fails against that daemon because it wrote
 * nothing but heartbeat records to the buffer.
 */
final class BackgroundSessionRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    private function bufferPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crush_bg_test_');
        $this->paths[] = $path;

        return $path;
    }

    private function runner(string $bufferPath, string $task = 'ship the thing'): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'sess_test_1',
            socketPath: $bufferPath . '.sock',
            bufferPath: $bufferPath,
            task: $task,
        );
    }

    // =========================================================================
    // Stopping the worker. supervise() used to send SIGTERM and then call a
    // bare pcntl_waitpid(), which a worker that traps TERM never returns from.
    // =========================================================================

    /**
     * A worker that IGNORES SIGTERM is escalated to signal 9 and reaped.
     *
     * MEASURED against the bare wait this replaced: a forked child that
     * installs an empty SIGTERM handler and loops leaves
     * `pcntl_waitpid($pid, $status)` unreturned — `timeout 5 php` exits 124.
     *
     * The consequence was not confined to the daemon. The supervisor's only
     * completion signal is a DEAD PID
     * ({@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::reapFinishedDaemon()}),
     * so a `supervise()` parked in that wait means the session reports
     * "running" for the life of the process, the unix socket is never unlinked,
     * and both processes leak.
     *
     * Run in a CHILD PHP PROCESS under an external clock, for the reason
     * {@see \SugarCraft\Crush\Tests\Hooks\ScriptHookTest} runs its drain
     * cases there: the failure being pinned is a HANG, and an in-process
     * assertion after a hang is never reached — it takes the whole suite out on
     * whatever external timeout CI happens to have, naming no test.
     */
    public function testAWorkerThatIgnoresSigtermIsEscalatedAndReaped(): void
    {
        $report = $this->stopWorkerBounded(trapsTerm: true);

        $this->assertTrue($report['reaped'], 'the worker was left running after stopWorker() returned');
        $this->assertLessThan(20.0, $report['elapsed'], 'stopWorker() did not return in bounded time');
        $this->assertStringContainsString('[session:task:escalate]', $report['log']);
        $this->assertStringNotContainsString('[session:task:unreaped]', $report['log']);
    }

    /**
     * The ordinary worker — no handler, dies on the default disposition — is
     * reaped on SIGTERM alone, without paying the escalation grace.
     *
     * This is the case that actually runs in production, and it is the one an
     * escalation can most easily make worse: a `stopWorker()` that always slept
     * out its grace before checking would satisfy the test above and add a
     * two-second stall to every `/stop`.
     */
    public function testAnOrdinaryWorkerIsReapedOnSigtermWithoutTheEscalationGrace(): void
    {
        $report = $this->stopWorkerBounded(trapsTerm: false);

        $this->assertTrue($report['reaped']);
        $this->assertLessThan(
            BackgroundSessionRunner::TERMINATE_GRACE_SECONDS,
            $report['elapsed'],
            'a well-behaved worker waited out the grace meant for one that ignores TERM',
        );
        $this->assertStringNotContainsString('[session:task:escalate]', $report['log']);
    }

    /**
     * Fork a worker, ask {@see BackgroundSessionRunner::stopWorker()} to stop
     * it, and report how that went — all inside a child PHP process bounded by
     * an external clock.
     *
     * @return array{elapsed: float, reaped: bool, log: string}
     */
    private function stopWorkerBounded(bool $trapsTerm): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $buffer = $this->bufferPath();
        $ready = $buffer . '.ready';
        $this->paths[] = $ready;
        $trap = $trapsTerm ? 'true' : 'false';

        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            \$runner = new SugarCraft\Crush\Sessions\BackgroundSessionRunner(
                sessionId: 'sess_stop_1',
                socketPath: {$this->export($buffer . '.sock')},
                bufferPath: {$this->export($buffer)},
                task: 'ship the thing',
            );

            \$worker = pcntl_fork();
            if (\$worker === 0) {
                if ({$trap}) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGTERM, static function (): void {});
                }
                // Announced only AFTER the handler is installed: signalled any
                // earlier and the default disposition kills the worker, which
                // is the well-behaved case wearing the other case's name.
                file_put_contents({$this->export($ready)}, 'y');
                while (true) {
                    usleep(50000);
                }
            }

            // `!== 'y'` and not `=== ''`: the file does not exist yet, and
            // `file_get_contents()` answers FALSE for that — a test that read
            // false as "ready" signalled the worker before its handler was
            // installed and so measured the well-behaved case twice.
            while (@file_get_contents({$this->export($ready)}) !== 'y') {
                usleep(10000);
            }

            \$method = new ReflectionMethod(\$runner, 'stopWorker');
            \$started = microtime(true);
            \$method->invoke(\$runner, \$worker);
            \$elapsed = microtime(true) - \$started;

            \$status = 0;
            // -1 is "not ours any more", i.e. already reaped by stopWorker();
            // 0 would mean it is still running.
            \$reaped = pcntl_waitpid(\$worker, \$status, WNOHANG) !== 0;
            if (!\$reaped) {
                posix_kill(\$worker, 9);
                pcntl_waitpid(\$worker, \$status);
            }

            fwrite(STDOUT, json_encode([
                'elapsed' => \$elapsed,
                'reaped' => \$reaped,
                'log' => (string) @file_get_contents({$this->export($buffer)}),
            ]));
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'bg_stop_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $decoded = json_decode($this->runBounded([PHP_BINARY, $file], 20.0), true);

        self::assertIsArray($decoded, 'the bounded child did not report a stopWorker() outcome');
        self::assertIsFloat($decoded['elapsed'] ?? null);
        self::assertIsBool($decoded['reaped'] ?? null);
        self::assertIsString($decoded['log'] ?? null);

        return $decoded;
    }

    /**
     * @param list<string> $argv
     */
    private function runBounded(array $argv, float $seconds): string
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $seconds;
        $out = '';
        $err = '';

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            if (proc_get_status($process)['running'] === false) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                self::fail("stopWorker() did not finish within {$seconds}s — it wedged");
            }

            usleep(10_000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', trim($err), 'the bounded child wrote to stderr');

        return $out;
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    public function testExecuteTaskWritesTheAssistantAnswerIntoTheBuffer(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend('the answer'));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[session:task:start]', $contents);
        $this->assertStringContainsString("the answer\n", $contents);
        $this->assertStringContainsString('[session:task:complete]', $contents);
    }

    public function testExecuteTaskSendsTheSpawnedTaskAsTheUserTurn(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('ok');

        $this->runner($buffer, 'audit the CI matrix')->executeTask($backend);

        $this->assertCount(1, $backend->history);
        $this->assertSame('audit the CI matrix', $backend->history[0]->content);
    }

    public function testExecuteTaskFlushesStreamedOutputLineByLine(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend("first\nsecond", streaming: true));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(0, $exit);
        // Streamed text is written once, not duplicated by a final write.
        $this->assertSame(1, substr_count($contents, 'first'));
        $this->assertSame(1, substr_count($contents, 'second'));
    }

    public function testExecuteTaskReportsFailureWhenTheBackendThrows(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend('', throw: new \RuntimeException("boom\nsecond line")));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[session:task:failed] boom second line', $contents);
        $this->assertStringNotContainsString('[session:task:complete]', $contents);
    }

    public function testServeClientAnswersHeartbeatAndReportsStatusOnResume(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "HEARTBEAT\nRESUME\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $stopped = $runner->serveClient($daemon, null);
        fclose($daemon);
        $reply = (string) stream_get_contents($client);
        fclose($client);

        $this->assertFalse($stopped);
        $this->assertStringContainsString('OK:session=sess_test_1', $reply);
        $this->assertStringContainsString('STATUS:running', $reply);
        $this->assertStringContainsString('[session:heartbeat]', (string) file_get_contents($buffer));
    }

    public function testServeClientReportsSettledResultOnResume(): void
    {
        $runner = $this->runner($this->bufferPath());
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "RESUME\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $runner->serveClient($daemon, 'completed');
        fclose($daemon);

        $this->assertStringContainsString('STATUS:completed', (string) stream_get_contents($client));
        fclose($client);
    }

    public function testServeClientReportsStopRequest(): void
    {
        $runner = $this->runner($this->bufferPath());
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "STOP\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $stopped = $runner->serveClient($daemon, null);
        fclose($daemon);

        $this->assertTrue($stopped);
        $this->assertStringContainsString('OK:stopping', (string) stream_get_contents($client));
        fclose($client);
    }

    public function testRunFailsFastWhenTheSupervisorSocketIsGone(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->run(new FakeRunnerBackend('unused'));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[session:connect:error]', (string) file_get_contents($buffer));
    }

    public function testAppendIgnoresEmptyWritesAndLogAddsALine(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $runner->append('');
        $this->assertSame('', (string) file_get_contents($buffer));

        $runner->log('[session:probe]');
        $this->assertSame("[session:probe]\n", (string) file_get_contents($buffer));
    }

    public function testFromConfigRoundTripsEveryField(): void
    {
        $runner = BackgroundSessionRunner::fromConfig([
            'sessionId' => 's1',
            'socketPath' => '/tmp/s1.sock',
            'bufferPath' => '/tmp/s1.buffer',
            'task' => 'do it',
            'workingDirectory' => '/tmp',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'timeoutSeconds' => 42,
        ]);

        $this->assertSame('s1', $runner->sessionId);
        $this->assertSame('/tmp/s1.sock', $runner->socketPath);
        $this->assertSame('/tmp/s1.buffer', $runner->bufferPath);
        $this->assertSame('do it', $runner->task);
        $this->assertSame('/tmp', $runner->workingDirectory);
        $this->assertSame('anthropic', $runner->provider);
        $this->assertSame('claude-sonnet-4-6', $runner->model);
        $this->assertSame(42, $runner->timeoutSeconds);
    }

    public function testMainRejectsAnIncompleteConfig(): void
    {
        $this->assertSame(1, BackgroundSessionRunner::main(null));
        $this->assertSame(1, BackgroundSessionRunner::main(['sessionId' => 's1']));
    }

    public function testHeartbeatIntervalStaysUnderTheSupervisorTimeout(): void
    {
        $this->assertLessThan(
            \SugarCraft\Crush\Sessions\BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS,
            BackgroundSessionRunner::HEARTBEAT_INTERVAL_SECS,
        );
    }

    public function testBackendFallsBackWhenTheAgentProviderCannotBeBuilt(): void
    {
        $buffer = $this->bufferPath();
        $runner = new BackgroundSessionRunner(
            sessionId: 's1',
            socketPath: $buffer . '.sock',
            bufferPath: $buffer,
            task: 'x',
            provider: 'definitely-not-a-provider',
        );

        $previousCmd = getenv('SUGARCRUSH_BACKEND_CMD');
        $previousProvider = getenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_PROVIDER=');
        putenv('SUGARCRUSH_BACKEND_CMD=printf hi');

        try {
            $backend = $runner->backend();
        } finally {
            putenv($previousCmd === false ? 'SUGARCRUSH_BACKEND_CMD' : 'SUGARCRUSH_BACKEND_CMD=' . $previousCmd);
            putenv($previousProvider === false ? 'SUGARCRUSH_PROVIDER' : 'SUGARCRUSH_PROVIDER=' . $previousProvider);
        }

        $this->assertInstanceOf(Backend::class, $backend);
        $this->assertStringContainsString('[session:provider:fallback]', (string) file_get_contents($buffer));
    }

    /**
     * An unusable permission policy is the one failure the provider fallback
     * cannot help with, and the catch-all used to blame the provider for it.
     *
     * The old `catch (\Throwable)` logged `[session:provider:fallback] <the
     * permission message>` and then called `Bootstrap::backend()`, which
     * builds the very same gate from the very same config and throws the very
     * same exception one line later — so the session died anyway, having first
     * written a log line that sends the reader to the wrong file. Rethrowing
     * matches the arm `NonInteractive::run()` and `Bootstrap::backend()`
     * already carry: the caller reports it as a task failure with the real
     * reason.
     */
    public function testAnUnusablePermissionPolicyIsNotReportedAsAProviderFallback(): void
    {
        $home = sys_get_temp_dir() . '/crush_bg_home_' . uniqid('', true);
        mkdir($home . '/.sugar-crush', 0700, true);
        file_put_contents($home . '/.sugar-crush/config.json', '{"permissionMode":"plan",}');

        $buffer = $this->bufferPath();
        $runner = new BackgroundSessionRunner(
            sessionId: 's1',
            socketPath: $buffer . '.sock',
            bufferPath: $buffer,
            task: 'x',
            provider: 'custom',
        );

        $previousHome = getenv('HOME');
        $previousKey = getenv('CUSTOM_API_KEY');
        putenv('HOME=' . $home);
        putenv('CUSTOM_API_KEY=k');
        // BOTH forms are redirected, because half a sandbox is not a sandbox:
        // {@see \SugarCraft\Crush\Support\HomeDirectory} reads `getenv()`,
        // and anything still holding a `$_SERVER['HOME']` copy (a nested
        // process, a library) must not be left pointing at the real home.
        $previousServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $home;

        try {
            $status = $runner->executeTask();
        } finally {
            putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
            putenv($previousKey === false ? 'CUSTOM_API_KEY' : 'CUSTOM_API_KEY=' . $previousKey);
            if ($previousServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServerHome;
            }
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }

        $log = (string) file_get_contents($buffer);

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('[session:provider:fallback]', $log);
        $this->assertStringContainsString('[session:task:failed]', $log);
        $this->assertStringContainsString('not usable JSON', $log);
    }
}

/**
 * Backend double: records the history it was asked to complete, optionally
 * streams the reply, optionally throws.
 */
final class FakeRunnerBackend implements Backend
{
    /** @var list<Message> */
    public array $history = [];

    public function __construct(
        private readonly string $reply,
        private readonly bool $streaming = false,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->history = $history;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->streaming && $onToken !== null) {
            foreach (str_split($this->reply, 3) as $chunk) {
                $onToken($chunk);
            }
        }

        return Message::assistant($this->reply);
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        throw new \LogicException('not used by the background runner');
    }
}

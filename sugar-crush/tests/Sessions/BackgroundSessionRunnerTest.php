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

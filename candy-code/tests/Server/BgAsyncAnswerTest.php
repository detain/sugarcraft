<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Code\Tests\Support\SupervisorPump;

/**
 * P4-12 CC-1 row f over the wire: `bg.spawn` and `bg.stop` answer through the
 * dispatcher's promise path. A spawn answers once its daemon authenticates, a
 * stop once its rungs have run, and neither holds the loop on the daemon while
 * it does so: a second spawn can stay pending while a stop of the first is
 * answered, and both still get their own answer.
 */
final class BgAsyncAnswerTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    private string $tempRoot = '';

    private ?ProtocolFixture $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!\function_exists($fn)) {
                self::markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->tempRoot = \sys_get_temp_dir() . '/bgasync' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempRoot, 0o700);
        $this->setUpDaemonFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        $this->tearDownDaemonFixture();
        ProtocolFixture::removeTree($this->tempRoot);
        parent::tearDown();
    }

    public function testASpawnAnswersWhenItsDaemonAuthenticatesAndItsStopAnswersAfterItsRungs(): void
    {
        $workerPidFile = $this->armHoldStillBackend();
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $client = $this->serve($supervisor)->client();

        $spawned = $client->call('bg.spawn', ['task' => 'hold still', 'idempotencyKey' => 'spawn-a']);
        $bgId = (string) $spawned['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $bgId);

        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Running, $supervisor->session($bgId)?->status, 'the answer comes once the session is live');
        $this->awaitDaemonServing($workerPidFile);
        self::assertSame(
            BackgroundStopOutcome::StoppedViaIpc->value,
            $client->call('bg.stop', ['bgId' => $bgId])['outcome'],
            'the stop answers once its rungs have run',
        );
    }

    public function testAStopAnswersWhileAnotherSpawnIsStillPending(): void
    {
        $firstWorkerPidFile = $this->armHoldStillBackend();
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor);
        $client = $fixture->client();

        $first = (string) $client->call('bg.spawn', ['task' => 'first', 'idempotencyKey' => 'spawn-first'])['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $first);
        $this->awaitDaemonServing($firstWorkerPidFile);
        $this->armHoldStillBackend();

        // The second launch is sent but not yet answered: its daemon has not authenticated.
        $client->raw('{"jsonrpc":"2.0","id":"spawn-second","method":"bg.spawn","params":{"task":"second","idempotencyKey":"spawn-second"}}');
        self::assertNull($client->response('spawn-second'), 'the second launch is still pending');

        $outcome = $client->call('bg.stop', ['bgId' => $first])['outcome'];
        self::assertSame(BackgroundStopOutcome::StoppedViaIpc->value, $outcome, 'the stop of the first session is answered while the second launch is pending');

        $deadline = \microtime(true) + 20.0;
        while ($client->response('spawn-second') === null && \microtime(true) < $deadline) {
            $fixture->context->background()?->poll();
            \usleep(20_000);
            $fixture->run(0.01);
        }
        $second = $client->response('spawn-second');
        self::assertArrayHasKey('result', $second ?? [], 'the second launch answers once its daemon authenticates');
        $secondId = (string) $second['result']['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $secondId);
        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Running, $supervisor->session($secondId)?->status, 'the second session is live');
    }

    public function testAStopOfAnUnknownSessionIsNotFoundOverTheWire(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $client = $this->serve($supervisor)->client();

        $answer = $client->request('bg.stop', ['bgId' => 'sess_20261120000000_0000dead']);

        self::assertSame('bg_not_found', $answer['error']['data']['kind'] ?? null, (string) \json_encode($answer));
    }

    /**
     * The serve answers a stop from its 50 ms pump, not the 2 s background poll:
     * no client tick and no poll run here, so only the pump can settle it before the
     * wait gives up. A graceful stop also stays graceful (StoppedViaIpc).
     */
    public function testAGracefulStopIsAnsweredByThePumpNotTheBackgroundPoll(): void
    {
        $workerPidFile = $this->armHoldStillBackend();
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor);
        $bgId = (string) $fixture->client()->call('bg.spawn', ['task' => 'hold still', 'idempotencyKey' => 'spawn-pump'])['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $bgId);
        $this->awaitDaemonServing($workerPidFile);

        $client = WireClient::open($fixture->dispatcher, 'c-pump');
        $client->hello();
        $client->raw('{"jsonrpc":"2.0","id":"stop-pump","method":"bg.stop","params":{"bgId":"' . $bgId . '"}}');

        // The wait is bounded by the stop's own IPC deadline (2 x STOP_IPC_TIMEOUT_SECONDS),
        // plus margin for a loaded shard. A tighter bound would fail on a graceful stop the
        // product still answers in time; no tick runs here, so only the pump can answer.
        $started = \microtime(true);
        while ($client->response('stop-pump') === null && \microtime(true) - $started < 3.0) {
            $fixture->context->pump();
            $fixture->run(0.01);
        }
        $answer = $client->response('stop-pump');

        self::assertArrayHasKey('result', $answer ?? [], 'the stop is answered by the pump within its IPC budget, not left to the 2 s poll');
        self::assertSame(BackgroundStopOutcome::StoppedViaIpc->value, $answer['result']['outcome'] ?? null, 'the graceful stop is not escalated to SIGTERM');
    }

    /**
     * An asynchronous launch refusal (its handshake window lapsing) answers
     * `bg_spawn_failed` and owes the cause to the server log, exactly as the
     * synchronous refusal does. The window is closed by advancing the supervisor's
     * clock rather than waiting out its five seconds.
     */
    public function testAnAsynchronousSpawnRefusalAnswersBgSpawnFailedAndLogsTheCause(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $lines = [];
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture = ProtocolFixture::new(supervisor: $supervisor, log: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $client = WireClient::open($fixture->dispatcher, 'c-refused');
        $client->hello();

        $client->raw('{"jsonrpc":"2.0","id":"spawn-refused","method":"bg.spawn","params":{"task":"never authenticates","idempotencyKey":"spawn-refused"}}');
        $supervisor->advanceSpawns(\microtime(true) + 60.0);
        $fixture->run(0.01);
        $answer = $client->response('spawn-refused');

        self::assertSame('bg_spawn_failed', $answer['error']['data']['kind'] ?? null, (string) \json_encode($answer));
        self::assertSame('the background session could not be started', $answer['error']['message'] ?? null, 'the wire carries no cause');
        self::assertCount(1, $lines, 'one log line per refused launch');
        self::assertStringStartsWith('bg.spawn failed: RuntimeException: ', $lines[0]);
    }

    /**
     * A stop naming a launch still waiting on its daemon is refused as not found,
     * and the refusal leaves the launch alone: it still answers live afterwards.
     */
    public function testAStopNamingALaunchStillPendingIsNotFoundAndTheLaunchStillAnswersLive(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor);
        $client = $fixture->client();

        $client->raw('{"jsonrpc":"2.0","id":"spawn-pending","method":"bg.spawn","params":{"task":"still starting","idempotencyKey":"spawn-pending"}}');
        $pending = (new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor);
        self::assertCount(1, $pending, 'the launch is pending on its daemon');
        $pendingId = (string) \array_key_first($pending);

        $refused = $client->request('bg.stop', ['bgId' => $pendingId]);
        self::assertSame('bg_not_found', $refused['error']['data']['kind'] ?? null, (string) \json_encode($refused));

        $deadline = \microtime(true) + 20.0;
        while ($client->response('spawn-pending') === null && \microtime(true) < $deadline) {
            $fixture->context->background()?->poll();
            \usleep(20_000);
            $fixture->run(0.01);
        }
        $answer = $client->response('spawn-pending');
        self::assertArrayHasKey('result', $answer ?? [], 'the launch still answers once its daemon authenticates');
        self::assertSame($pendingId, (string) $answer['result']['bgId']);
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $pendingId);
        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Running, $supervisor->session($pendingId)?->status, 'the refused stop did not touch the launch');
    }

    /**
     * Arm the hold-still backend so the daemon's worker writes its own pid to a
     * fresh file once it runs; the trait kills that pid in tearDown. Returns the file.
     */
    private function armHoldStillBackend(): string
    {
        $pidFile = $this->fixtureHome . '/backend-' . \bin2hex(\random_bytes(4)) . '.pid';
        $this->fixturePidFiles[] = $pidFile;
        \putenv('CANDY_CODE_BACKEND_CMD=echo $$ > ' . \escapeshellarg($pidFile) . '; cat >/dev/null; exec sleep 30');

        return $pidFile;
    }

    /**
     * Hold a stop back until the daemon serves its own socket. `bg.spawn` answers on
     * the handshake, and the supervisor then closes its listener, leaving the socket
     * node in place. Until the daemon forks its worker, unlinks that node and binds
     * its own, rung 1 cannot connect and the stop goes straight to SIGTERM
     * (`stopped-signal`), which is the red a loaded full ParaTest run showed. The
     * worker's pid file is the readiness signal {@see StoppableDaemonFixtureTrait}
     * already uses. The daemon re-binds right after the fork, while the worker
     * first builds its backend and starts a shell, so the file appears well after
     * the re-bind. Nothing here ticks the supervisor or polls the session.
     */
    private function awaitDaemonServing(string $workerPidFile): void
    {
        $deadline = \microtime(true) + 15.0;
        while (\trim((string) @\file_get_contents($workerPidFile)) === '') {
            if (\microtime(true) >= $deadline) {
                self::fail('the background worker never started its backend, so the daemon never re-bound its socket');
            }
            SupervisorPump::pace(0.05);
        }
    }

    private function serve(BackgroundSupervisor $supervisor): ProtocolFixture
    {
        return $this->fixture = ProtocolFixture::new(supervisor: $supervisor);
    }

    /** @return array{pid: int, startTime: int|null} */
    private function daemonOf(BackgroundSupervisor $supervisor, string $id): array
    {
        $ipc = $this->ipcOf($supervisor, $id);

        return ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
    }
}

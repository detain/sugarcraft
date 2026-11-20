<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;

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
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $client = $this->serve($supervisor)->client();

        $spawned = $client->call('bg.spawn', ['task' => 'hold still', 'idempotencyKey' => 'spawn-a']);
        $bgId = (string) $spawned['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $bgId);

        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Running, $supervisor->session($bgId)?->status, 'the answer comes once the session is live');
        self::assertSame(
            BackgroundStopOutcome::StoppedViaIpc->value,
            $client->call('bg.stop', ['bgId' => $bgId])['outcome'],
            'the stop answers once its rungs have run',
        );
    }

    public function testAStopAnswersWhileAnotherSpawnIsStillPending(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor);
        $client = $fixture->client();

        $first = (string) $client->call('bg.spawn', ['task' => 'first', 'idempotencyKey' => 'spawn-first'])['bgId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $first);

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

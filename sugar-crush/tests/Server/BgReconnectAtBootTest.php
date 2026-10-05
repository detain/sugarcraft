<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Protocol\Schema\Definitions;
use SugarCraft\Crush\Protocol\Schema\EventSchemas;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Protocol\Schema\Schema;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tests\Sessions\StoppableDaemonFixtureTrait;

/**
 * Roadmap O-4b: background (`/bg`) agents over the server.
 *
 * A server that starts re-adopts the daemons an earlier server or TUI of its
 * root left running — the dispatcher's start is the server's caller of
 * `BackgroundSupervisor::reconnect()` — and from then on reports every
 * background session's moves as `bg.started` / `bg.status` / `bg.completed`.
 * `bg.spawn` starts one the way `/bg` does, and `bg.inject` sends a settled
 * one's answer into a session as a prompt.
 *
 * The supervisor works under a scratch temp root, so no planted record ever
 * meets another process's; "the process that spawned it has exited" is a
 * record whose owner is a pid that has exited.
 */
final class BgReconnectAtBootTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** Scratch temp root for the supervisor. SHORT: a socket path inside it must fit 108 bytes. */
    private string $tempRoot = '';

    private ?ProtocolFixture $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('posix_getuid')) {
            self::markTestSkipped('the session index is named by uid and needs ext-posix');
        }
        $this->tempRoot = \sys_get_temp_dir() . '/bg' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempRoot, 0o700);
    }

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        if ($this->fixtureHome !== '') {
            $this->tearDownDaemonFixture();
        }
        ProtocolFixture::removeTree($this->tempRoot);

        parent::tearDown();
    }

    // ── boot ───────────────────────────────────────────────────────────

    public function testStartReadoptsWhatAnEarlierProcessLeftAndTheFirstPollReportsIt(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        $id = 'sess_20261004120000_cafecafe';
        $this->plantRecord($id, $fixture->root, "the answer is 42\n[session:usage] tokens=1200 cost=0.010000\n[session:task:completed]\n");

        self::assertNull($supervisor->getSession($id), 'nothing is adopted before the server starts');
        $fixture->dispatcher->start();
        $client = $fixture->client();

        self::assertTrue($fixture->context->background()?->isBooted());
        self::assertSame(BackgroundSessionStatus::Running, $supervisor->getSession($id)?->status, 'adopted at start; the reap is the poll\'s');
        self::assertSame([$id => BackgroundSupervisor::ADOPTED_STATUS], $fixture->context->background()->reported());

        $listed = $this->checked($client, 'bg.list');
        self::assertSame([$id], \array_column($listed['items'], 'bgId'));
        self::assertTrue($listed['items'][0]['adopted']);
        self::assertSame('running', $listed['items'][0]['status']);

        self::assertSame(2, $fixture->context->background()->poll());

        self::assertSame(['bg.started', 'bg.completed'], $client->eventTypes());
        $started = $client->events('bg.started')[0];
        self::assertNull($started['sessionId'], 'a server-scope event');
        self::assertArrayNotHasKey('seq', $started);
        self::assertTrue($started['data']['adopted']);
        $completed = $client->events('bg.completed')[0]['data'];
        self::assertSame('completed', $completed['status']);
        self::assertSame(1200, $completed['tokensUsed']);
        self::assertSame(\strlen("the answer is 42\n"), $completed['outputBytes']);
        foreach ($client->events() as $event) {
            $this->assertEventFits($event);
        }

        self::assertSame(0, $fixture->context->background()->poll(), 'reported once');
        self::assertSame("the answer is 42\n", $this->checked($client, 'bg.output', ['bgId' => $id])['text']);
        $listed = $this->checked($client, 'bg.list');
        self::assertSame(['completed'], \array_column($listed['items'], 'status'), 'a settled session is still listed');
    }

    public function testBootHappensOnceAndOnlyForThisRoot(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        $this->plantRecord('sess_20261004120000_0000aaaa', $fixture->root . '/elsewhere', "x\n[session:task:completed]\n");

        $fixture->dispatcher->start();
        $fixture->dispatcher->start();

        self::assertSame([], $fixture->context->background()?->reported(), 'another project\'s session is left for that project');
        self::assertSame([], $fixture->context->background()->boot(), 'a second boot adopts nothing');
    }

    public function testAStatusChangeIsReportedAsBgStatus(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        $client = $fixture->client();
        $supervisor->addSession($this->session('sess_20261004120000_0000bbbb', BackgroundSessionStatus::Running));
        $events = $fixture->context->background();
        self::assertNotNull($events);

        self::assertSame(1, $events->poll());
        $supervisor->addSession($this->session('sess_20261004120000_0000bbbb', BackgroundSessionStatus::Streaming));
        self::assertSame(1, $events->poll());

        self::assertSame(['bg.started', 'bg.status'], $client->eventTypes());
        self::assertSame(['bgId' => 'sess_20261004120000_0000bbbb', 'status' => 'streaming', 'previous' => 'running'], $client->events('bg.status')[0]['data']);
        self::assertFalse($client->events('bg.started')[0]['data']['adopted']);
        foreach ($client->events() as $event) {
            $this->assertEventFits($event);
        }
    }

    // ── bg.inject ──────────────────────────────────────────────────────

    public function testInjectSendsTheSettledResultIntoASessionWithItsMentionsLeftAsText(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        \file_put_contents($fixture->root . '/README.md', 'project readme');
        $id = 'sess_20261004120000_0000cccc';
        $this->plantRecord($id, $fixture->root, "see @README.md for the details\n[session:task:completed]\n");
        $client = $fixture->client();
        $fixture->dispatcher->start();
        $fixture->context->background()?->poll();
        $sessionId = $fixture->session($client);

        $injected = $this->checked($client, 'bg.inject', ['bgId' => $id, 'sessionId' => $sessionId]);

        self::assertSame($id, $injected['bgId']);
        self::assertSame($sessionId, $injected['sessionId']);
        self::assertSame('started', $injected['admitted']);
        self::assertCount(1, $fixture->backend->sent);
        self::assertTrue(BackgroundSession::isAnnouncement($fixture->backend->sent[0]), 'the session reads the same report the TUI queues');
        self::assertStringContainsString('see @README.md for the details', $fixture->backend->sent[0]);
        $user = \array_values(\array_filter(
            $fixture->hub->get($sessionId)?->history() ?? [],
            static fn (Message $m): bool => $m->role === Role::User,
        ));
        self::assertSame([], $user[0]->attachments, 'the background model\'s @path is not the user asking for a file');

        $fixture->backend->settle(Message::assistant('noted'));
        $fixture->run(0.08);
    }

    public function testInjectRefusesARunningSessionAnUnknownOneAndAnUnknownTarget(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        $client = $fixture->client();
        $supervisor->addSession($this->session('sess_20261004120000_0000dddd', BackgroundSessionStatus::Running));
        $sessionId = $fixture->session($client);

        $running = $client->request('bg.inject', ['bgId' => 'sess_20261004120000_0000dddd', 'sessionId' => $sessionId]);
        self::assertSame(-32009, $running['error']['code']);
        self::assertSame('bg_not_settled', $running['error']['data']['kind']);

        $unknown = $client->request('bg.inject', ['bgId' => 'sess_20261004120000_ffffffff', 'sessionId' => $sessionId]);
        self::assertSame('bg_not_found', $unknown['error']['data']['kind']);

        $supervisor->addSession($this->session('sess_20261004120000_0000eeee', BackgroundSessionStatus::Completed));
        $noTarget = $client->request('bg.inject', ['bgId' => 'sess_20261004120000_0000eeee', 'sessionId' => 'no-such-session']);
        self::assertSame('session_not_found', $noTarget['error']['data']['kind']);
        self::assertSame([], $fixture->backend->sent);
    }

    // ── bg.spawn ───────────────────────────────────────────────────────

    public function testSpawnStartsADaemonAnnouncesItAndItsAnswerComesBack(): void
    {
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!\function_exists($fn)) {
                self::markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
        \putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf SPAWNED42');
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture($supervisor);
        $client = $fixture->client();

        $spawned = $this->checked($client, 'bg.spawn', ['task' => "write\nthe answer", 'idempotencyKey' => 'spawn-1']);
        $bgId = (string) $spawned['bgId'];
        $ipc = $this->ipcOf($supervisor, $bgId);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        \array_push($this->fixtureFiles, $ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? '');

        self::assertMatchesRegularExpression(BackgroundSupervisor::SESSION_ID_PATTERN, $bgId);
        self::assertSame('write', $spawned['name'], 'named the way /bg names it: the first line');
        self::assertSame("write\nthe answer", $spawned['task']);
        self::assertFalse($spawned['adopted']);
        self::assertSame($fixture->root, $spawned['workingDirectory'], 'spawned into the served root');
        self::assertSame($bgId, $client->events('bg.started')[0]['data']['bgId'] ?? null, 'announced before the answer');
        self::assertSame($bgId, $client->call('bg.spawn', ['task' => 'write the answer', 'idempotencyKey' => 'spawn-1'])['bgId'], 'a retry is not a second daemon');

        $events = $fixture->context->background();
        self::assertNotNull($events);
        $deadline = \microtime(true) + 20.0;
        while (\microtime(true) < $deadline && $client->events('bg.completed') === []) {
            $events->poll();
            \usleep(100_000);
        }

        self::assertSame('completed', $client->events('bg.completed')[0]['data']['status'] ?? null, 'the spawned daemon never settled');
        self::assertStringContainsString('SPAWNED42', $this->checked($client, 'bg.output', ['bgId' => $bgId])['text']);
        foreach ($client->events() as $event) {
            $this->assertEventFits($event);
        }
    }

    public function testSpawnRefusesAnEmptyTaskAnUnknownAgentAndADrainingServer(): void
    {
        $fixture = $this->fixture(new BackgroundSupervisor(tempRoot: $this->tempRoot));
        $client = $fixture->client();

        self::assertSame(-32602, $client->request('bg.spawn', ['task' => '   '])['error']['code']);
        self::assertSame('agent_not_found', $client->request('bg.spawn', ['task' => 'x', 'agent' => 'nobody'])['error']['data']['kind']);
        $fixture->context->beginDraining();
        self::assertSame('draining', $client->request('bg.spawn', ['task' => 'x'])['error']['data']['kind']);
        self::assertSame([], $client->eventTypes());
    }

    public function testAWorkspaceWithoutASupervisorListsNothingAndSpawnsNothing(): void
    {
        $fixture = $this->fixture(null);
        $client = $fixture->client();
        $fixture->dispatcher->start();

        self::assertNull($fixture->context->background());
        self::assertSame(['items' => []], $client->call('bg.list'));
        $refused = $client->request('bg.spawn', ['task' => 'x']);
        self::assertSame(-32030, $refused['error']['code']);
        self::assertSame('bg_unavailable', $refused['error']['data']['kind']);
    }

    // ── docs ───────────────────────────────────────────────────────────

    public function testServerDocStatesThePollAndTheEvents(): void
    {
        $doc = (string) \file_get_contents(__DIR__ . '/../../docs/SERVER.md');
        $start = \strpos($doc, '### Background sessions');
        self::assertNotFalse($start, 'SERVER.md has a Background sessions section');
        $section = \substr($doc, $start, (int) \strpos($doc, "\n## ", $start) - $start);
        $flat = (string) \preg_replace('/\s+/', ' ', $section);

        self::assertStringContainsString('every ' . (int) BackgroundEvents::POLL_SECONDS . ' s', $flat);
        foreach ([BackgroundEvents::STARTED, BackgroundEvents::STATUS, BackgroundEvents::COMPLETED, 'bg.spawn', 'bg.inject'] as $name) {
            self::assertStringContainsString('`' . $name . '`', $flat, $name);
        }
        foreach ([BackgroundEvents::STARTED, BackgroundEvents::STATUS, BackgroundEvents::COMPLETED] as $type) {
            self::assertSame(EventType::SCOPE_SERVER, EventType::scope($type));
        }
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function fixture(?BackgroundSupervisor $supervisor): ProtocolFixture
    {
        return $this->fixture = ProtocolFixture::new(supervisor: $supervisor);
    }

    /**
     * Leave what a process that has since exited would have: a private IPC
     * directory holding the session's files (its daemon already gone), and
     * the session's index record.
     */
    private function plantRecord(string $id, string $workingDirectory, string $buffer): void
    {
        $uid = \posix_getuid();
        $dir = $this->tempRoot . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . $uid . '_' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0o700);
        foreach (['.sock' => '', '.buffer' => $buffer, '.buffer.log' => '', '.token' => 'token'] as $suffix => $contents) {
            \file_put_contents($dir . '/' . $id . $suffix, $contents);
        }
        $index = $this->tempRoot . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . $uid . BackgroundSupervisor::INDEX_DIR_SUFFIX;
        if (!\is_dir($index)) {
            \mkdir($index, 0o700);
        }
        $dead = $this->deadPid();
        $record = $index . '/' . $id . '.json';
        \file_put_contents($record, (string) \json_encode([
            'id' => $id,
            'name' => 'planted job',
            'task' => 'do the planted work',
            'workingDirectory' => $workingDirectory,
            'timeoutSeconds' => 120,
            'tags' => [],
            'createdAt' => \time() - 60,
            'agent' => $this->agent()->toArray(),
            'socketPath' => $dir . '/' . $id . '.sock',
            'bufferPath' => $dir . '/' . $id . '.buffer',
            'tokenPath' => $dir . '/' . $id . '.token',
            'pid' => $dead,
            'startTime' => null,
            'owner' => ['pid' => $dead, 'startTime' => 1],
        ]));
        \chmod($record, 0o600);
    }

    private function session(string $id, BackgroundSessionStatus $status): BackgroundSession
    {
        return new BackgroundSession(
            id: $id,
            name: 'in memory',
            agent: $this->agent(),
            task: 'stay in memory',
            workingDirectory: $this->tempRoot,
            status: $status,
        );
    }

    private function deadPid(): int
    {
        $proc = \proc_open([\PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $pid = (int) \proc_get_status($proc)['pid'];
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        \proc_close($proc);
        self::assertSame('', $stderr, 'the stand-in process said nothing');

        return $pid;
    }

    private function agent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * Call $method and assert its result fits the method's result schema.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checked(WireClient $client, string $method, array $params = []): array
    {
        $result = $client->call($method, $params);
        $schema = MethodSchemas::result($method);
        self::assertNotNull($schema, $method);
        self::assertSame([], ProtocolSchema::errors($result, $schema, $method), $method . ' answered outside its schema');

        return $result;
    }

    /** @param array<string, mixed> $event */
    private function assertEventFits(array $event): void
    {
        $type = (string) $event['type'];
        self::assertSame([], ProtocolSchema::errors($event, Schema::ref(Definitions::ENVELOPE)), $type . ' envelope');
        $data = EventSchemas::data($type);
        self::assertNotNull($data, $type . ' has no data schema');
        self::assertSame([], ProtocolSchema::errors($event['data'], $data, $type), $type . ' data');
        self::assertSame(EventType::isDurable($type), $event['durable'], $type . ' durability');
    }
}

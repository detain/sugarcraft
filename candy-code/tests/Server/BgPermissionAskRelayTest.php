<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Sessions\BackgroundSession;
use SugarCraft\Code\Sessions\BackgroundSessionRunner;
use SugarCraft\Code\Sessions\BackgroundSessionStatus;
use SugarCraft\Code\Sessions\BackgroundSupervisor;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;

use function json_encode;

/**
 * ISS-004: a permission ask inside a spawned background daemon is relayed to
 * the server's clients as a server-scope `permission.asked` named by the
 * background id, answerable with `permission.respond`, listed by
 * `permission.pending`, and cancelled when the session settles unanswered.
 *
 * The live daemon of each planted record is this very process, so adoption
 * keeps the session running while the test answers; the settle-cancel test
 * moves the in-memory status by hand, which is exactly what a reaped dead
 * daemon would do.
 */
final class BgPermissionAskRelayTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    private string $tempRoot = '';

    private ?ProtocolFixture $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('posix_getuid')) {
            self::markTestSkipped('the session index is named by uid and needs ext-posix');
        }
        $this->tempRoot = \sys_get_temp_dir() . '/rp' . \bin2hex(\random_bytes(4));
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

    public function testARelayedAskIsAnnouncedAsAServerScopePermissionAskedEvent(): void
    {
        [$fixture, $id] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0001', $this->askRow('a1b2c3d4e5f60718', 'Bash'));
        $client = $fixture->client();
        $events = $fixture->context->background();
        self::assertNotNull($events);
        $events->poll();

        self::assertContains('permission.asked', $client->eventTypes());
        $asked = $client->events('permission.asked')[0]['data'];
        self::assertSame($id, $asked['sessionId'], 'the background id wears the sessionId name');
        self::assertSame($id, $asked['bgId']);
        self::assertSame('a1b2c3d4e5f60718', $asked['askId']);
        self::assertSame('Bash', $asked['tool']);
        self::assertSame(['command' => 'make -j4'], $asked['arguments']);
        self::assertSame(['once', 'reject'], $asked['options']);
        foreach ($client->events() as $event) {
            self::assertSame([], \SugarCraft\Code\Protocol\Schema\ProtocolSchema::errors(
                $event,
                \SugarCraft\Code\Protocol\Schema\Schema::ref(\SugarCraft\Code\Protocol\Schema\Definitions::ENVELOPE),
            ), $event['type'] . ' envelope');
        }
        self::assertSame(0, $events->pollAsks(), 'a second poll sees the same ask and announces nothing twice');
    }

    public function testPermissionPendingListsRelayedAsksBesideHostedOnes(): void
    {
        [$fixture, $id] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0002', $this->askRow('b1b2c3d4e5f60718', 'Edit'));
        $client = $fixture->client();

        $all = $this->checked($client, 'permission.pending');
        $items = \array_values(\array_filter($all['items'], static fn (array $row): bool => ($row['bgId'] ?? null) === $id));
        self::assertCount(1, $items);
        self::assertSame('b1b2c3d4e5f60718', $items[0]['askId']);
        self::assertSame($id, $items[0]['sessionId']);

        $mine = $this->checked($client, 'permission.pending', ['sessionId' => $id]);
        self::assertCount(1, $mine['items'], 'filtering by the background id keeps only its question');

        $sessionId = $fixture->session($client);
        $theirs = $this->checked($client, 'permission.pending', ['sessionId' => $sessionId]);
        self::assertSame([], $theirs['items'], 'a hosted session does not inherit the daemon\'s question');
    }

    public function testAnsweringOverTheWireWritesTheLineTheDaemonReadsAndEmitsSettled(): void
    {
        [$fixture, $id] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0003', $this->askRow('c1b2c3d4e5f60718', 'Bash'));
        $client = $fixture->client();
        $fixture->context->background()?->poll();

        $answered = $this->checked($client, 'permission.respond', [
            'sessionId' => $id,
            'askId' => 'c1b2c3d4e5f60718',
            'reply' => 'once',
            'note' => 'fine',
        ]);
        self::assertSame(['applied' => true, 'askId' => 'c1b2c3d4e5f60718', 'reply' => 'once', 'cascaded' => []], $answered);

        $settled = $client->events('permission.settled');
        self::assertCount(1, $settled);
        self::assertSame($id, $settled[0]['data']['sessionId']);
        self::assertSame('once', $settled[0]['data']['reply']);

        $answerLine = $this->answerRows($fixture, 'sess_20261017000000_aaaa0003');
        self::assertCount(1, $answerLine);
        self::assertSame('c1b2c3d4e5f60718', $answerLine[0]['askId']);
        self::assertSame('once', $answerLine[0]['reply']);
        self::assertSame('fine', $answerLine[0]['note']);
    }

    public function testASecondAnswerIsRefusedTheSameWayAnAnsweredHostedQuestionIs(): void
    {
        [$fixture, $id] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0004', $this->askRow('d1b2c3d4e5f60718', 'Bash'));
        $client = $fixture->client();
        $fixture->context->background()?->poll();
        $this->checked($client, 'permission.respond', ['sessionId' => $id, 'askId' => 'd1b2c3d4e5f60718', 'reply' => 'reject']);

        $again = $client->request('permission.respond', ['sessionId' => $id, 'askId' => 'd1b2c3d4e5f60718', 'reply' => 'once']);
        self::assertSame(-32009, $again['error']['code']);
        self::assertSame('already_resolved', $again['error']['data']['kind']);
        self::assertSame('reject', $again['error']['data']['resolved']['reply']);
        self::assertCount(1, $this->answerRows($fixture, 'sess_20261017000000_aaaa0004'), 'the first answer stands');
    }

    public function testCascadeOnARelayedQuestionIsRefusedAndAnUnknownQuestionLooksUnknown(): void
    {
        [$fixture, $id] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0005', $this->askRow('e1b2c3d4e5f60718', 'Bash'));
        $client = $fixture->client();
        $fixture->context->background()?->poll();

        $cascade = $client->request('permission.respond', ['sessionId' => $id, 'askId' => 'e1b2c3d4e5f60718', 'reply' => 'reject', 'cascade' => true]);
        self::assertSame('cascade_unavailable', $cascade['error']['data']['kind']);
        self::assertSame([], $this->answerRows($fixture, 'sess_20261017000000_aaaa0005'), 'a refused cascade answers nothing');

        $ghost = $client->request('permission.respond', ['sessionId' => $id, 'askId' => 'ffffffffffffffff', 'reply' => 'once']);
        self::assertSame('ask_not_found', $ghost['error']['data']['kind']);

        $elsewhere = $client->request('permission.respond', ['sessionId' => 'sess_20261017000000_99999999', 'askId' => 'e1b2c3d4e5f60718', 'reply' => 'once']);
        self::assertSame('ask_not_found', $elsewhere['error']['data']['kind']);
    }

    public function testAQuestionWhoseSessionSettlesIsCancelledNotAnswerable(): void
    {
        [$fixture, $id, $supervisor] = $this->serveWithAskedQuestion('sess_20261017000000_aaaa0006', $this->askRow('f1b2c3d4e5f60718', 'Bash'));
        $client = $fixture->client();
        $events = $fixture->context->background();
        self::assertNotNull($events);
        $events->poll();
        self::assertCount(1, $client->events('permission.asked'));

        // A dead daemon's reap looks exactly like this to the event pump.
        $supervisor->addSession(new BackgroundSession(
            id: $id,
            name: 'ask relay job',
            agent: $this->agent(),
            task: 'hold a question',
            workingDirectory: $fixture->root,
            status: BackgroundSessionStatus::Completed,
        ));
        $events->poll();

        $settled = $client->events('permission.settled');
        self::assertCount(1, $settled);
        self::assertTrue($settled[0]['data']['cancelled']);
        self::assertSame('f1b2c3d4e5f60718', $settled[0]['data']['askId']);

        $late = $client->request('permission.respond', ['sessionId' => $id, 'askId' => 'f1b2c3d4e5f60718', 'reply' => 'once']);
        self::assertSame('ask_not_found', $late['error']['data']['kind'], 'a cancelled question is not answerable');
        self::assertSame([], $events->pendingAsks());
    }

    public function testWithoutASupervisorTheLegacyAskNotFoundIsByteForByteWhatItAlwaysWas(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $error = $client->request('permission.respond', ['sessionId' => 'sess_20261017000000_00000000', 'askId' => 'a1b2c3d4e5f60718', 'reply' => 'once']);
        self::assertSame('ask_not_found', $error['error']['data']['kind']);
        self::assertStringContainsString('is not open, so no question of it is open', $error['error']['message']);
    }

    public function testTheSpawnPathCarriesTheAskWindowIntoTheDaemonConfigAndTheRunnerHonorsIt(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $code = $supervisor->buildSessionDaemonCode(
            socketPath: '/s.sock',
            bufferPath: '/s.buffer',
            tokenPath: '/s.token',
            sessionId: 'sess_20261017000000_aaaa00aa',
            task: 't',
            workingDirectory: '/w',
            provider: 'p',
            model: 'm',
            timeoutSeconds: 60,
            permissionAskTimeout: 30.0,
        );
        self::assertStringContainsString('permissionAskTimeout', $code);
        $without = $supervisor->buildSessionDaemonCode(
            socketPath: '/s.sock',
            bufferPath: '/s.buffer',
            tokenPath: '/s.token',
            sessionId: 'sess_20261017000000_aaaa00aa',
            task: 't',
            workingDirectory: '/w',
            provider: 'p',
            model: 'm',
            timeoutSeconds: 60,
        );
        self::assertStringNotContainsString('permissionAskTimeout', $without, 'a /bg spawn without a server window is byte-identical to what it was');

        self::assertSame(30.0, (float) $this->configFromDaemonCode($code)['permissionAskTimeout']);
        self::assertArrayNotHasKey('permissionAskTimeout', $this->configFromDaemonCode($without));
    }

    public function testTheRunnerReadsTheAskWindowFromItsConfigAndDefaultsToOff(): void
    {
        $reflected = new \ReflectionClass(BackgroundSessionRunner::class);
        $params = $reflected->getConstructor()?->getParameters() ?? [];
        $last = $params[count($params) - 1] ?? null;
        self::assertNotNull($last);
        self::assertSame('permissionAskTimeout', $last->getName());
        self::assertSame(-1.0, $last->getDefaultValue(), 'no config key means no relay: the pre-ISS-004 approver stays');

        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Sessions/BackgroundSessionRunner.php');
        self::assertSame(1, preg_match('/permissionAskTimeout[^\n]*is_numeric/s', $source), 'fromConfig parses the key before trusting it');
        self::assertSame(1, preg_match('/withPermissionApprover\(\s*\(new BackgroundPermissionRelay\(/s', (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Sessions/BackgroundSessionRunner.php')));

        $methods = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Protocol/Methods/BgMethods.php');
        self::assertSame(1, preg_match('/permissionAskTimeout:\s*\$call->server->config\(\)->askTimeoutSeconds/', $methods), 'bg.spawn hands every spawned daemon the server ask window');
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * A server whose workspace adopted a live session (daemon = this process)
     * that has one relayed question open, already written to the ask channel.
     *
     * @return array{0: ProtocolFixture, 1: string, 2: BackgroundSupervisor}
     */
    private function serveWithAskedQuestion(string $id, string $askLine): array
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->fixture = ProtocolFixture::new(supervisor: $supervisor);
        $files = $this->plantRecord($id, (int) getmypid(), $this->deadOwner(), $fixture->root);
        \file_put_contents($files['dir'] . '/' . $id . '.ask', $askLine);
        $fixture->dispatcher->start();

        return [$fixture, $id, $supervisor];
    }

    /** @return array<string, mixed> one line of the ask protocol, as the relay writes it */
    private function askRow(string $askId, string $tool): string
    {
        return (string) json_encode([
            'askId' => $askId,
            'toolCallId' => 'call-7',
            'tool' => $tool,
            'arguments' => ['command' => 'make -j4'],
            'reason' => 'Run make -j4?',
            'source' => 'gate',
            'mode' => 'auto',
            'options' => ['once', 'reject'],
            'requestedAt' => date('c'),
        ]) . "\n";
    }

    /** @return list<array<string, mixed>> */
    private function answerRows(ProtocolFixture $fixture, string $id): array
    {
        $rows = [];
        $dir = $this->plantedDirs[$id] ?? null;
        self::assertNotNull($dir, 'the session was planted by this test');
        $path = $dir . '/' . $id . '.answer';
        if (!\is_file($path)) {
            return [];
        }
        foreach (explode("\n", (string) \file_get_contents($path)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /** @var array<string, string> */
    private array $plantedDirs = [];

    /**
     * @return array{record: string, socket: string, buffer: string, log: string, token: string, dir: string}
     */
    private function plantRecord(string $id, int $daemonPid, array $owner, string $workingDirectory): array
    {
        $dir = $this->tempRoot . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . '_' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0o700);
        $this->plantedDirs[$id] = $dir;
        $files = [
            'socket' => $dir . '/' . $id . '.sock',
            'buffer' => $dir . '/' . $id . '.buffer',
            'log' => $dir . '/' . $id . '.buffer.log',
            'token' => $dir . '/' . $id . '.token',
        ];
        \file_put_contents($files['socket'], '');
        \file_put_contents($files['buffer'], "[session:heartbeat]\n");
        \file_put_contents($files['log'], '');
        \file_put_contents($files['token'], 'token');

        $index = $this->tempRoot . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
        if (!\is_dir($index)) {
            \mkdir($index, 0o700);
        }
        $record = $index . '/' . $id . '.json';
        \file_put_contents($record, (string) json_encode([
            'id' => $id,
            'name' => 'ask relay job',
            'task' => 'hold a question',
            'workingDirectory' => $workingDirectory,
            'timeoutSeconds' => 120,
            'tags' => [],
            'createdAt' => time() - 60,
            'agent' => $this->agent()->toArray(),
            'socketPath' => $files['socket'],
            'bufferPath' => $files['buffer'],
            'tokenPath' => $files['token'],
            'pid' => $daemonPid,
            'startTime' => BackgroundSupervisor::procStartTime($daemonPid),
            'owner' => $owner,
        ]));
        \chmod($record, 0o600);

        return ['record' => $record] + $files + ['dir' => $dir];
    }

    /** @return array{pid: int, startTime: ?int} */
    private function deadOwner(): array
    {
        $proc = \proc_open([PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        self::assertIsResource($proc);
        $pid = (int) \proc_get_status($proc)['pid'];
        \proc_close($proc);

        return ['pid' => $pid, 'startTime' => 1];
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

    /** @return array<string, mixed> */
    private function configFromDaemonCode(string $code): array
    {
        // The daemon code embeds its config as one var_export'ed JSON literal.
        self::assertSame(1, preg_match("/json_decode\('((?:[^'\\\\]|\\\\.)*)', true\)/", $code, $match), 'the daemon code embeds one config literal');
        $config = json_decode(str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]), true);
        self::assertIsArray($config);

        return $config;
    }

    /** @return array<string, mixed> */
    private function checked(WireClient $client, string $method, array $params = []): array
    {
        $reply = $client->request($method, $params);
        self::assertArrayHasKey('result', $reply, $method . ': ' . json_encode($reply['error'] ?? null));

        return $reply['result'];
    }
}

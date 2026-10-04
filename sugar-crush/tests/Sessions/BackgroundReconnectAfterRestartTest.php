<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackgroundTickMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Usage;
use React\Promise\PromiseInterface;

/**
 * Roadmap 4.3-3: a `/bg` daemon outlives the TUI that spawned it, and a
 * RESTARTED TUI now re-adopts it.
 *
 * Before this `BackgroundSupervisor::reconnect()` walked only the sessions held
 * in memory — which die with the process — and the IPC directory was a random
 * name per process, so a restart adopted nothing even with a caller (and it had
 * none). `spawnSession()` now leaves a record in the per-uid index, and
 * `reconnect($project)` adopts the records whose owning process is gone.
 *
 * Every test runs against a scratch temp root handed to the supervisor, so no
 * record ever meets another process's; "the process that spawned it has
 * exited" is a record whose owner is a pid that has exited.
 *
 * Also here, because they are what makes an adopted result worth announcing
 * (W4-i handoff): the daemon's usage record reaches the stats line, a
 * failure carries its reason, and a timeout settles TimedOut.
 */
final class BackgroundReconnectAfterRestartTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** Scratch temp root. SHORT: the socket path inside it must fit 108 bytes. */
    private string $root = '';

    /** The project the planted sessions were spawned for. */
    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_getuid')) {
            $this->markTestSkipped('the session index is named by uid and needs ext-posix');
        }

        $this->root = sys_get_temp_dir() . '/rc' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700);
        $this->project = $this->root . '/project';
        mkdir($this->project, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureHome !== '') {
            $this->tearDownDaemonFixture();
        }
        $this->removeTree($this->root);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Adoption
    // -------------------------------------------------------------------------

    public function testASessionAnEarlierProcessSpawnedIsAdoptedAndItsResultReaped(): void
    {
        $files = $this->plantRecord('sess_20261004120000_aaaaaaaa', $this->deadPid(), $this->deadOwner(), $this->project,
            "the answer is 42\n[session:usage] tokens=1234 cost=0.012300\n[session:task:completed]\n");

        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $adopted = $supervisor->reconnect($this->project);

        $this->assertSame(['sess_20261004120000_aaaaaaaa'], array_keys($adopted));
        $session = $adopted['sess_20261004120000_aaaaaaaa'];
        $this->assertSame(BackgroundSessionStatus::Running, $session->status, 'adopted, not settled: the reap is tick()\'s');
        $this->assertSame('planted job', $session->name);
        $this->assertSame('do the planted work', $session->task);
        $this->assertTrue($supervisor->hasActiveSessions(), 'an adopted session arms the host\'s background poll');

        $record = json_decode((string) file_get_contents($files['record']), true);
        $this->assertSame(getmypid(), $record['owner']['pid'], 'the record is re-stamped with its new owner');

        $supervisor->tick();

        $settled = $supervisor->getSession('sess_20261004120000_aaaaaaaa');
        $this->assertSame(BackgroundSessionStatus::Completed, $settled?->status);
        $this->assertSame("the answer is 42\n", $settled->output, 'usage and task records are bookkeeping, never output');
        $this->assertSame(1234, $settled->tokensUsed);
        $this->assertEqualsWithDelta(0.0123, $settled->costUsd, 1e-9);
        $this->assertStringContainsString('1.2K tokens', $settled->announcement());
        $this->assertStringContainsString('$0.0123', $settled->announcement());

        foreach ($files as $path) {
            $this->assertFileDoesNotExist($path, 'a settled adopted session left a file behind');
        }
        $this->assertDirectoryDoesNotExist($files['dir'], 'its IPC directory goes once empty');
        $this->assertDirectoryDoesNotExist(dirname($files['record']), 'and so does the emptied index');
        $this->assertFalse($supervisor->hasActiveSessions());
    }

    public function testASessionWhoseOwnerIsStillRunningIsNotAdopted(): void
    {
        $files = $this->plantRecord('sess_20261004120000_bbbbbbbb', $this->deadPid(), $this->liveOwner(), $this->project, "x\n");

        $this->assertSame([], (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($this->project));
        $this->assertFileExists($files['record'], 'a live owner\'s record is left alone');
        $this->assertFileExists($files['buffer']);
    }

    public function testTwoRestartedSupervisorsCannotBothAdoptOneDaemon(): void
    {
        $this->plantRecord('sess_20261004120000_cccccccc', (int) getmypid(), $this->deadOwner(), $this->project, "[session:heartbeat]\n");

        $first = (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($this->project);
        $second = (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($this->project);

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'the first adopter is now the live owner');
    }

    public function testAnotherProjectsSessionIsLeftForThatProjectAndNoProjectAdoptsNothing(): void
    {
        $files = $this->plantRecord('sess_20261004120000_dddddddd', $this->deadPid(), $this->deadOwner(), $this->project, "x\n[session:task:completed]\n");
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere, 0o700);

        $this->assertSame([], (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($elsewhere));
        $this->assertSame([], (new BackgroundSupervisor(tempRoot: $this->root))->reconnect(null));
        $this->assertFileExists($files['record'], 'kept for the project it was spawned for');

        $this->assertCount(1, (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($this->project . '/.'), 'the same directory spelled differently still matches');
    }

    public function testARecordPointingOutsideAPrivateIpcDirectoryIsRefusedAndPruned(): void
    {
        $id = 'sess_20261004120000_eeeeeeee';
        $foreign = $this->root . '/foreign';
        mkdir($foreign, 0o700);
        foreach (['.sock', '.buffer', '.token'] as $suffix) {
            file_put_contents($foreign . '/' . $id . $suffix, 'keep me');
        }
        $index = $this->indexDir();
        file_put_contents($index . '/' . $id . '.json', (string) json_encode($this->record($id, $foreign, $this->deadPid(), $this->deadOwner(), $this->project)));

        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);

        $this->assertSame([], $supervisor->reconnect($this->project));
        $this->assertFileDoesNotExist($index . '/' . $id . '.json', 'a record that names files it may not touch is dropped');
        foreach (['.sock', '.buffer', '.token'] as $suffix) {
            $this->assertFileExists($foreign . '/' . $id . $suffix, 'and those files are never deleted');
        }
    }

    public function testAnIndexDirectoryThatIsNotPrivateIsNotTrusted(): void
    {
        $this->plantRecord('sess_20261004120000_ffffffff', $this->deadPid(), $this->deadOwner(), $this->project, "x\n");
        chmod($this->indexDir(), 0o755);

        $this->assertSame([], (new BackgroundSupervisor(tempRoot: $this->root))->reconnect($this->project));
    }

    public function testASpawnedDaemonIsReadoptedByTheNextProcessAndItsAnswerComesBack(): void
    {
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; sleep 1; printf READOPTED42');

        $first = new BackgroundSupervisor(tempRoot: $this->root);
        $session = $first->spawnSession(
            name: 'outlives its tui',
            agent: $this->agent(),
            task: 'finish after the restart',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        );
        $ipc = $this->ipcOf($first, $session->id);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        array_push($this->fixtureFiles, $ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? '');

        $recordPath = $this->indexDir() . '/' . $session->id . '.json';
        $this->assertFileExists($recordPath, 'spawnSession() leaves an index record');
        $this->assertSame(0o600, fileperms($recordPath) & 0o777);
        $record = json_decode((string) file_get_contents($recordPath), true);
        $this->assertSame($ipc['pid'], $record['pid']);
        $this->assertSame(getmypid(), $record['owner']['pid']);
        $this->assertStringNotContainsString((string) file_get_contents($ipc['tokenPath']), (string) file_get_contents($recordPath), 'the token PATH, never the token');

        // The spawning TUI exits: its process is gone, its memory with it.
        $record['owner'] = $this->deadOwner();
        file_put_contents($recordPath, (string) json_encode($record));
        unset($first);

        $second = new BackgroundSupervisor(tempRoot: $this->root);
        $adopted = $second->reconnect($this->fixtureHome);
        $this->assertArrayHasKey($session->id, $adopted, 'the restarted process adopts the daemon');

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && $second->getSession($session->id)?->isActive()) {
            $second->tick();
            usleep(100_000);
        }

        $settled = $second->getSession($session->id);
        $this->assertSame(BackgroundSessionStatus::Completed, $settled?->status, 'the adopted daemon never settled');
        $this->assertStringContainsString('READOPTED42', $settled->output);
        $this->assertFileDoesNotExist($recordPath);
        $this->assertFileDoesNotExist($ipc['bufferPath']);
    }

    // -------------------------------------------------------------------------
    // The host side: Bootstrap folds adopted sessions in, the first poll reports
    // -------------------------------------------------------------------------

    public function testTheFirstPollAfterARestartAnnouncesTheAdoptedResult(): void
    {
        $this->plantRecord('sess_20261004120000_abababab', $this->deadPid(), $this->deadOwner(), $this->project,
            "finished while you were away\n[session:task:completed]\n");
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $adopted = $supervisor->reconnect($this->project);

        // What Bootstrap::chat() passes: every adopted id at ADOPTED_STATUS.
        $chat = new Chat(
            backend: new EchoBackend(),
            backgroundSupervisor: $supervisor,
            backgroundStatuses: array_fill_keys(array_keys($adopted), BackgroundSupervisor::ADOPTED_STATUS),
            projectRoot: $this->project,
        );
        $this->assertNotNull($chat->subscriptions(), 'the adopted session arms the background poll');

        [$next] = $chat->update(new BackgroundTickMsg());

        $this->assertSame(['sess_20261004120000_abababab' => 'completed'], $next->backgroundStatuses());
        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains("Background session sess_20261004120000_abababab ('planted job') was re-adopted from an earlier run and is completed.", $contents);
        $announced = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === Role::User));
        $this->assertCount(1, $announced);
        $this->assertStringContainsString('finished while you were away', $announced[0]->content);
    }

    public function testAnAtPathInABackgroundResultIsNotAttachedAsAFileMention(): void
    {
        file_put_contents($this->project . '/secret.txt', 'TOP SECRET');
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession((new BackgroundSession(
            id: 'sess_20261004120000_cdcdcdcd',
            name: 'job',
            agent: $this->agent(),
            task: 'look around',
            workingDirectory: $this->project,
        ))->withOutput("I read @secret.txt for you\n")->withStatus(BackgroundSessionStatus::Completed));

        $chat = new Chat(
            backend: new EchoBackend(),
            backgroundSupervisor: $supervisor,
            backgroundStatuses: ['sess_20261004120000_cdcdcdcd' => 'running'],
            projectRoot: $this->project,
        );
        [$next] = $chat->update(new BackgroundTickMsg());

        $announced = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === Role::User));
        $this->assertCount(1, $announced);
        $this->assertStringContainsString('@secret.txt', $announced[0]->content);
        $this->assertSame([], $announced[0]->attachments, 'the model\'s @path is not the user asking for a file');
        $this->assertTrue(BackgroundSession::isAnnouncement($announced[0]->content));
        $this->assertFalse(BackgroundSession::isAnnouncement('please read @secret.txt'));
    }

    public function testBootstrapChatHandsTheAdoptedSessionsToTheChatAtBoot(): void
    {
        // Pinned off the source rather than by launching: a second
        // Bootstrap::chat()-launching file destabilises the load-sensitive
        // forked-completion tests (see RulePathScopingWiringTest).
        $method = new \ReflectionMethod(Bootstrap::class, 'chat');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertMatchesRegularExpression(
            '/backgroundStatuses:\s*array_fill_keys\(\s*array_keys\(\$workspace->backgroundSupervisor\?->reconnect\(\$root\)\s*\?\?\s*\[\]\),\s*BackgroundSupervisor::ADOPTED_STATUS,?\s*\)/',
            $body,
        );
    }

    // -------------------------------------------------------------------------
    // What a reaped result carries (W4-i handoff)
    // -------------------------------------------------------------------------

    public function testAFailedDaemonCarriesItsReason(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $this->plantRecord('sess_20261004120000_12121212', $this->deadPid(), $this->deadOwner(), $this->project,
            "[session:task:start]\n[session:task:failed] provider exploded\n[session:task:failed]\n[session:daemon:exit]\n");
        $supervisor->reconnect($this->project);

        $supervisor->tick();

        $settled = $supervisor->getSession('sess_20261004120000_12121212');
        $this->assertSame(BackgroundSessionStatus::Failed, $settled?->status);
        $this->assertSame('provider exploded', $settled->error);
        $this->assertStringContainsString('Error: provider exploded', $settled->announcement());
    }

    public function testATimeoutSettlesTimedOutAndStaysSettled(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);
        $this->plantRecord('sess_20261004120000_34343434', $this->deadPid(), $this->deadOwner(), $this->project,
            "partial work\n[session:task:timeout]\n[session:daemon:exit]\n");
        $supervisor->reconnect($this->project);

        $supervisor->tick();
        $settled = $supervisor->getSession('sess_20261004120000_34343434');
        $this->assertSame(BackgroundSessionStatus::TimedOut, $settled?->status, 'a timeout is not a failure');
        $this->assertStringContainsString('120 s limit', (string) $settled->error);
        $this->assertStringContainsString("('planted job') timed out]", $settled->announcement());
        $this->assertFalse($supervisor->hasActiveSessions(), 'a settled session no longer holds the poll open');

        $supervisor->tick();
        $this->assertSame($settled, $supervisor->getSession('sess_20261004120000_34343434'), 'and is never re-reaped');
    }

    public function testTheRunnerReportsWhatTheTurnCostInARecordTheSupervisorReads(): void
    {
        $buffer = $this->root . '/runner.buffer';
        file_put_contents($buffer, '');
        $runner = new BackgroundSessionRunner(
            sessionId: 'sess_20261004120000_56565656',
            socketPath: $buffer . '.sock',
            bufferPath: $buffer,
            task: 'count things',
        );
        $backend = new class implements Backend {
            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('counted')->withUsage(Usage::new(totalTokens: 1500, costUsd: 0.0025));
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                throw new \LogicException('not used by the background runner');
            }
        };

        $this->assertSame(0, $runner->executeTask($backend));

        $written = (string) file_get_contents($buffer);
        $this->assertStringContainsString(BackgroundSessionRunner::USAGE_RECORD . " tokens=1500 cost=0.002500\n[session:task:complete]", $written);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function indexDir(): string
    {
        $dir = $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
        if (!is_dir($dir)) {
            mkdir($dir, 0o700);
        }

        return $dir;
    }

    /**
     * Leave what a supervisor that has since exited would have: a private IPC
     * directory holding the session's files, and its index record.
     *
     * @return array{record: string, socket: string, buffer: string, log: string, token: string, dir: string}
     */
    private function plantRecord(string $id, int $daemonPid, array $owner, string $workingDirectory, string $buffer): array
    {
        $dir = $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . '_' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700);
        $files = [
            'socket' => $dir . '/' . $id . '.sock',
            'buffer' => $dir . '/' . $id . '.buffer',
            'log' => $dir . '/' . $id . '.buffer.log',
            'token' => $dir . '/' . $id . '.token',
        ];
        file_put_contents($files['socket'], '');
        file_put_contents($files['buffer'], $buffer);
        file_put_contents($files['log'], '');
        file_put_contents($files['token'], 'token');

        $record = $this->indexDir() . '/' . $id . '.json';
        file_put_contents($record, (string) json_encode($this->record($id, $dir, $daemonPid, $owner, $workingDirectory)));
        chmod($record, 0o600);

        return ['record' => $record] + $files + ['dir' => $dir];
    }

    /**
     * @param array{pid: int, startTime: ?int} $owner
     * @return array<string, mixed>
     */
    private function record(string $id, string $dir, int $daemonPid, array $owner, string $workingDirectory): array
    {
        return [
            'id' => $id,
            'name' => 'planted job',
            'task' => 'do the planted work',
            'workingDirectory' => $workingDirectory,
            'timeoutSeconds' => 120,
            'tags' => [],
            'createdAt' => time() - 60,
            'agent' => $this->agent()->toArray(),
            'socketPath' => $dir . '/' . $id . '.sock',
            'bufferPath' => $dir . '/' . $id . '.buffer',
            'tokenPath' => $dir . '/' . $id . '.token',
            'pid' => $daemonPid,
            'startTime' => BackgroundSupervisor::procStartTime($daemonPid),
            'owner' => $owner,
        ];
    }

    /** @return array{pid: int, startTime: ?int} */
    private function deadOwner(): array
    {
        return ['pid' => $this->deadPid(), 'startTime' => 1];
    }

    /** @return array{pid: int, startTime: ?int} */
    private function liveOwner(): array
    {
        $pid = (int) getmypid();

        return ['pid' => $pid, 'startTime' => BackgroundSupervisor::procStartTime($pid)];
    }

    private function deadPid(): int
    {
        $proc = proc_open([PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

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
}

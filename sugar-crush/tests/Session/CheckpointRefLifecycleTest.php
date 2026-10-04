<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-1: a checkpoint row's workspace snapshot and the git ref pinning
 * it live and die together — captured into the row the turn wrote, dropped
 * when the row is pruned, rewound or deleted, re-pinned under the new
 * session when `/branch` copies it — and the turn takes it before it can
 * touch a file.
 *
 * @see EnhancedSessionStore::captureWorkspace()
 * @see Chat::dispatchTurn()
 */
final class CheckpointRefLifecycleTest extends TestCase
{
    use HomeSandboxTrait;

    private const LIFECYCLE_SESSION = 'lifecycle-session';

    private string $tmp;

    private string $repo;

    private EnhancedSessionStore $store;

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_cpref_' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/db', 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();
        // A refused capture warns once (item 3.A-2); keep its error_log()
        // copy out of the runner's output.
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->tmp . '/error.log');

        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        $this->gitAt('init', '-q');
        file_put_contents($this->repo . '/file.txt', "base\n");
        $this->gitAt('add', '.');
        $this->gitAt('commit', '-q', '-m', 'base');

        $this->store = new EnhancedSessionStore($this->tmp . '/db/session.db');
        $this->store->createSession(self::LIFECYCLE_SESSION, 'echo', 'echo');
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        $this->previousErrorLog === false ? ini_restore('error_log') : ini_set('error_log', $this->previousErrorLog);
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testTheOutcomeIsStoredInTheRowAndReadBackWithIt(): void
    {
        $index = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['messages' => [['role' => 'user', 'content' => 'hi']], 'inputBuf' => '']);
        file_put_contents($this->repo . '/file.txt', "dirty\n");

        $workspace = $this->store->captureWorkspace(self::LIFECYCLE_SESSION, $index, $this->repo);

        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));
        $state = $this->store->getCheckpoint(self::LIFECYCLE_SESSION, $index);
        self::assertSame($workspace, $state[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY]);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $state['messages'], 'the rest of the row is untouched');
        self::assertSame([WorkspaceCheckpointer::refFor(self::LIFECYCLE_SESSION, $index)], $this->refs());
    }

    public function testARefusalIsStoredTooSoAFileRestoreCanSayWhy(): void
    {
        $index = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);

        $workspace = $this->store->captureWorkspace(self::LIFECYCLE_SESSION, $index, $this->tmp . '/home');

        self::assertSame('refused', $workspace['status']);
        self::assertSame($workspace, $this->store->getCheckpoint(self::LIFECYCLE_SESSION, $index)[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY]);
    }

    public function testASnapshotForARowThatIsGoneIsUnpinnedAgain(): void
    {
        $workspace = $this->store->captureWorkspace(self::LIFECYCLE_SESSION, 41, $this->repo);

        self::assertSame('captured', $workspace['status']);
        self::assertSame([], $this->refs(), 'no row took it, so no ref may outlive the call');
    }

    public function testPruningPastTheCapDropsTheOldestRowsRef(): void
    {
        $first = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);
        $this->store->captureWorkspace(self::LIFECYCLE_SESSION, $first, $this->repo);
        $second = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);
        $this->store->captureWorkspace(self::LIFECYCLE_SESSION, $second, $this->repo);

        for ($i = 0; $i < 99; $i++) {
            $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);
        }

        self::assertNull($this->store->getCheckpoint(self::LIFECYCLE_SESSION, $first), 'fixture: the 101st save pruned the first row');
        self::assertSame([WorkspaceCheckpointer::refFor(self::LIFECYCLE_SESSION, $second)], $this->refs());
    }

    /**
     * Item 3.A-2: a rewind sets the rows it steps over aside on the redo
     * stack, refs and all, so `/redo` can put their files back; the next
     * save discards the stack, and only then do the refs go.
     */
    public function testARewindKeepsTheRefsOfTheRowsItSetsAsideUntilTheNextSave(): void
    {
        $kept = $this->checkpointWithSnapshot();
        $restored = $this->checkpointWithSnapshot();
        $later = $this->checkpointWithSnapshot();

        $state = $this->store->restoreCheckpoint(self::LIFECYCLE_SESSION, $restored);

        self::assertSame('captured', $state[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY]['status'], 'the restored state still names its snapshot');
        self::assertSame(
            array_map(static fn (int $i): string => WorkspaceCheckpointer::refFor(self::LIFECYCLE_SESSION, $i), [$kept, $restored, $later]),
            $this->refs(),
            'nothing is unpinned while /redo can still reach it',
        );

        $next = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);

        self::assertSame($restored, $next, 'the discarded rows free their indexes');
        self::assertSame([WorkspaceCheckpointer::refFor(self::LIFECYCLE_SESSION, $kept)], $this->refs());
    }

    public function testABranchPinsItsOwnCopyAndOutlivesItsSource(): void
    {
        $index = $this->checkpointWithSnapshot();
        $source = $this->store->getCheckpoint(self::LIFECYCLE_SESSION, $index)[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY];

        $branch = $this->store->forkSession(self::LIFECYCLE_SESSION, SessionKind::Branch);

        $copied = $this->store->getCheckpoint($branch, $index)[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY];
        self::assertSame(WorkspaceCheckpointer::refFor($branch, $index), $copied['ref']);
        self::assertSame($source['sha'], $copied['sha']);
        self::assertEqualsCanonicalizing([$source['ref'], $copied['ref']], $this->refs());

        $this->store->deleteSession(self::LIFECYCLE_SESSION);

        self::assertSame([$copied['ref']], $this->refs(), 'deleting the source unpins only its own snapshot');
    }

    public function testRetentionPruningDropsThePrunedSessionsRefs(): void
    {
        $this->checkpointWithSnapshot();
        $this->store->createSession('fresh-session', 'echo', 'echo');
        $kept = $this->store->saveCheckpoint('fresh-session', ['inputBuf' => '']);
        $this->store->captureWorkspace('fresh-session', $kept, $this->repo);
        $pdo = (new \ReflectionProperty(EnhancedSessionStore::class, 'pdo'))->getValue($this->store);
        $pdo->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', self::LIFECYCLE_SESSION]);

        self::assertSame(1, $this->store->pruneSessions(30));

        self::assertSame([WorkspaceCheckpointer::refFor('fresh-session', $kept)], $this->refs());
    }

    /**
     * The wiring: a turn from a Chat with a project root snapshots the files
     * when its Cmd runs, before the backend is reached.
     */
    public function testATurnSnapshotsTheWorkspaceBeforeTheBackendRuns(): void
    {
        file_put_contents($this->repo . '/file.txt', "before the turn\n");
        $refsAtDispatch = null;
        $backend = $this->backend(function () use (&$refsAtDispatch): void {
            $refsAtDispatch = $this->refs();
            file_put_contents($this->repo . '/file.txt', "the agent's edit\n");
        });
        $chat = new Chat(
            inputBuf: 'edit the file',
            backend: $backend,
            sessionStore: $this->store,
            currentSessionId: self::LIFECYCLE_SESSION,
            currentSessionName: 'named',
            projectRoot: $this->repo,
        );

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertTrue($sent->inFlight);
        self::assertSame([], $this->refs(), 'update() itself runs no git');
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(AsyncCmd::class, $cmd());
        self::assertSame([WorkspaceCheckpointer::refFor(self::LIFECYCLE_SESSION, 0)], $refsAtDispatch, 'pinned before the turn ran');

        $workspace = $this->store->getCheckpoint(self::LIFECYCLE_SESSION, 0)[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY];
        $restored = $this->store->workspaceCheckpointer($this->repo)->restore($workspace);
        self::assertSame('restored', $restored['status'], $restored['reason']);
        self::assertSame("before the turn\n", file_get_contents($this->repo . '/file.txt'));
    }

    public function testAChatWithoutAProjectRootTakesNoSnapshot(): void
    {
        $chat = new Chat(
            inputBuf: 'hello',
            backend: $this->backend(static function (): void {}),
            sessionStore: $this->store,
            currentSessionId: self::LIFECYCLE_SESSION,
            currentSessionName: 'named',
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertInstanceOf(\Closure::class, $cmd);
        $cmd();

        self::assertArrayNotHasKey(EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY, $this->store->getCheckpoint(self::LIFECYCLE_SESSION, 0));
        self::assertSame([], $this->refs());
    }

    private function checkpointWithSnapshot(): int
    {
        $index = $this->store->saveCheckpoint(self::LIFECYCLE_SESSION, ['inputBuf' => '']);
        file_put_contents($this->repo . '/file.txt', 'turn ' . $index . "\n");
        $workspace = $this->store->captureWorkspace(self::LIFECYCLE_SESSION, $index, $this->repo);
        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));

        return $index;
    }

    /** @return list<string> */
    private function refs(): array
    {
        $out = $this->gitAt('for-each-ref', '--format=%(refname)', 'refs/sugar-crush/');

        return $out === '' ? [] : explode("\n", $out);
    }

    private function gitAt(string ...$args): string
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false -C ' . escapeshellarg($this->repo);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    /** @param \Closure(): void $onTurn */
    private function backend(\Closure $onTurn): Backend
    {
        return new class($onTurn) implements Backend {
            public function __construct(private readonly \Closure $onTurn) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                ($this->onTurn)();

                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                ($this->onTurn)();

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\BackgroundTickMsg;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * `/fork` runs its prompt ON TOP OF the conversation it copied (Part II #30).
 *
 * Before this, `Chat::handleForkCommand()` copied the session rows and handed
 * the copy's id to the background session only as a `session:<id>` tag, and
 * {@see BackgroundSessionRunner::executeTask()} called
 * `complete([Message::user($task)])` — so a fork answered with no idea what it
 * had been forked from, and the copy it was supposedly continuing never saw
 * the answer. Every test below fails against that runner.
 */
final class ForkCarriesHistoryTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_fork_history_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);
    }

    // =========================================================================
    // The runner seam
    // =========================================================================

    public function testAForkSessionSendsTheCopiedHistoryAheadOfTheTask(): void
    {
        $store = $this->storeWithConversation('fork-1');
        $backend = $this->recordingBackend('done');

        $exit = $this->runner('fork-1', 'now write the tests')->executeTask($backend, $store);

        $this->assertSame(0, $exit);
        $this->assertSame(
            [
                ['user', 'port the renderer'],
                ['assistant', 'Ported; the diff renderer is next.'],
                ['user', 'now write the tests'],
            ],
            array_map(static fn(Message $m): array => [$m->role->value, $m->content], $backend->history),
            'the copied conversation (minus its UI-only rows) leads the request, then the task',
        );
    }

    public function testTheReplyIsWrittenBackIntoTheForkedSession(): void
    {
        $store = $this->storeWithConversation('fork-2');

        $this->runner('fork-2', 'now write the tests')->executeTask($this->recordingBackend('Tests written.'), $store);

        $saved = Chat::loadTranscript($store, 'fork-2');
        $this->assertSame(
            ['port the renderer', 'Ported; the diff renderer is next.', 'Background session started.', 'now write the tests', 'Tests written.'],
            array_map(static fn(Message $m): string => $m->content, $saved),
            'the fork keeps every stored row and gains the task and its answer',
        );
        $this->assertSame(Role::Assistant, $saved[4]->role);
    }

    public function testAMissingForkedSessionFailsTheTaskLoudly(): void
    {
        $store = new EnhancedSessionStore(':memory:');
        $backend = $this->recordingBackend('should not run');
        $runner = $this->runner('gone', 'keep going');

        $exit = $runner->executeTask($backend, $store);

        $this->assertSame(1, $exit);
        $this->assertNull($backend->history, 'a fork with no history must not be answered as if it were a fresh /bg');
        $this->assertStringContainsString(
            '[session:task:failed] could not load forked session gone',
            (string) file_get_contents($runner->bufferPath),
        );
    }

    public function testAForkSomeoneElseHoldsStillRunsButLeavesItsTranscriptAlone(): void
    {
        $store = $this->storeWithConversation('fork-3', $this->dir . '/session.db');
        $before = $store->loadTranscript('fork-3');
        $held = $store->lockSession('fork-3');
        $this->assertNotNull($held);
        $runner = $this->runner('fork-3', 'now write the tests');
        $backend = $this->recordingBackend('done');

        $exit = $runner->executeTask($backend, $store);
        $held->release();

        $this->assertSame(0, $exit);
        $this->assertCount(3, $backend->history, 'the turn still ran on the copy\'s history');
        $this->assertSame($before, $store->loadTranscript('fork-3'), 'the holder\'s transcript was not overwritten');
        $this->assertStringContainsString('[session:fork:locked]', (string) file_get_contents($runner->bufferPath));
    }

    public function testAFailedForkTurnStillRecordsTheTaskInTheCopy(): void
    {
        $store = $this->storeWithConversation('fork-4');

        $exit = $this->runner('fork-4', 'now write the tests')
            ->executeTask($this->recordingBackend('', new \RuntimeException('provider down')), $store);

        $saved = Chat::loadTranscript($store, 'fork-4');
        $this->assertSame(1, $exit);
        $this->assertSame('now write the tests', $saved[3]->content);
        $this->assertTrue($saved[4]->uiOnly, 'the failure is a notice, never a row the model reads as its own answer');
        $this->assertStringContainsString('provider down', $saved[4]->content);
    }

    public function testAPlainBackgroundSessionNeverTouchesTheStore(): void
    {
        $store = $this->storeWithConversation('untouched');
        $before = $store->loadTranscript('untouched');
        $backend = $this->recordingBackend('ok');

        $this->runner('', 'just this')->executeTask($backend, $store);

        $this->assertCount(1, $backend->history);
        $this->assertSame($before, $store->loadTranscript('untouched'));
    }

    public function testTheDaemonConfigCarriesTheForkedIdAndNeverTheTranscript(): void
    {
        $supervisor = new BackgroundSupervisor();
        $args = [
            'socketPath' => '/tmp/s.sock',
            'bufferPath' => '/tmp/s.buffer',
            'tokenPath' => '/tmp/s.token',
            'sessionId' => 'sess_x',
            'task' => 'continue',
            'workingDirectory' => '/tmp',
            'provider' => 'anthropic',
            'model' => 'm',
            'timeoutSeconds' => 60,
        ];

        $fork = $supervisor->buildSessionDaemonCode(...$args, forkedSessionId: 'fork-xyz');
        $plain = $supervisor->buildSessionDaemonCode(...$args);

        $this->assertStringContainsString('"forkedSessionId":"fork-xyz"', $fork);
        $this->assertStringNotContainsString('forkedSessionId', $plain);

        $runner = BackgroundSessionRunner::fromConfig(['bufferPath' => 'b', 'socketPath' => 's', 'forkedSessionId' => 'fork-xyz']);
        $this->assertSame('fork-xyz', $runner->forkedSessionId);
        $this->assertSame('', BackgroundSessionRunner::fromConfig(['bufferPath' => 'b', 'socketPath' => 's'])->forkedSessionId);
    }

    // =========================================================================
    // End to end: the /fork command through a real daemon
    // =========================================================================

    /**
     * The whole path, nothing doubled: `/fork` in a Chat, a real daemon, the
     * shell-out backend. The backend command saves the stdin it was given, so
     * this proves the daemon's request carried the forked conversation, and the
     * store read back afterwards proves the answer landed in the copy.
     *
     * HOME is pointed at the scratch dir because the daemon opens the launch's
     * own store ({@see \SugarCraft\Crush\Cli\Bootstrap::sessionStore()}),
     * which is where a production Chat's store lives too.
     */
    public function testForkEndToEndContinuesTheConversationAndSavesTheAnswer(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
            $this->markTestSkipped('the background daemon needs pcntl + posix');
        }

        $stdinCopy = $this->dir . '/backend-stdin.json';
        $saved = [];
        foreach (['SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_PROVIDER'] as $name) {
            $saved[$name] = getenv($name);
        }
        $this->useHomeSandbox($this->dir);
        putenv('SUGARCRUSH_BACKEND_CMD=cat > ' . escapeshellarg($stdinCopy) . '; printf FORKDONE');
        putenv('SUGARCRUSH_PROVIDER=');

        $supervisor = new BackgroundSupervisor();
        try {
            mkdir($this->dir . '/.sugar-crush', 0700, true);
            $store = $this->storeWithConversation('sess-src', $this->dir . '/.sugar-crush/session.db');
            $chat = new Chat(sessionStore: $store, currentSessionId: 'sess-src', backgroundSupervisor: $supervisor);

            [$chat, $cmd] = $this->submitLine($chat, '/fork now write the tests');
            $this->assertInstanceOf(\Closure::class, $cmd);
            $spawned = $this->resolveAsyncCmd($cmd);
            $this->assertInstanceOf(BackgroundSessionSpawnedMsg::class, $spawned);
            $this->assertNull($spawned->error, (string) $spawned->error);

            $deadline = microtime(true) + 20.0;
            while (microtime(true) < $deadline && $supervisor->getSession((string) $spawned->sessionId)?->isActive()) {
                [$chat] = $chat->update(new BackgroundTickMsg());
                usleep(100_000);
            }
            if ($supervisor->getSession((string) $spawned->sessionId)?->isActive()) {
                $supervisor->stopSession((string) $spawned->sessionId);
                $this->fail('the /fork daemon never settled');
            }

            $forkId = null;
            foreach ($store->listSessions() as $row) {
                if ($row['id'] !== 'sess-src') {
                    $forkId = (string) $row['id'];
                }
            }
            $this->assertNotNull($forkId, 'the /fork made a copy');

            $sent = (string) @file_get_contents($stdinCopy);
            $this->assertStringContainsString('port the renderer', $sent, 'the daemon\'s request carried the forked conversation');
            $this->assertStringContainsString('now write the tests', $sent);
            $this->assertStringNotContainsString('Background session started.', $sent, 'UI-only rows stay off the wire');

            $fork = Chat::loadTranscript(new EnhancedSessionStore($this->dir . '/.sugar-crush/session.db'), $forkId);
            $this->assertSame('FORKDONE', $fork[array_key_last($fork)]->content, 'the answer was saved into the copy');
            $this->assertSame('now write the tests', $fork[count($fork) - 2]->content);
            $this->assertCount(3, Chat::loadTranscript($store, 'sess-src'), 'the session the user stayed on is unchanged');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function storeWithConversation(string $sessionId, ?string $dbPath = null): EnhancedSessionStore
    {
        // In memory unless a test needs the file: a fresh on-disk store costs
        // ~1.8 s of schema fsyncs, and only the lock and end-to-end cases need
        // one (an in-memory store's lock is unenforced, and the daemon is a
        // separate process).
        $store = new EnhancedSessionStore($dbPath ?? ':memory:');
        $store->saveTranscript($sessionId, [
            Message::user('port the renderer'),
            Message::assistant('Ported; the diff renderer is next.'),
            Message::notice('Background session started.'),
        ]);

        return $store;
    }

    private function runner(string $forkedSessionId, string $task): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'sess_bg_fork',
            socketPath: $this->dir . '/bg.sock',
            bufferPath: $this->dir . '/bg-' . bin2hex(random_bytes(3)) . '.buffer',
            task: $task,
            forkedSessionId: $forkedSessionId,
        );
    }

    private function recordingBackend(string $reply, ?\Throwable $throw = null): Backend
    {
        return new class ($reply, $throw) implements Backend {
            /** @var list<Message>|null */
            public ?array $history = null;

            public function __construct(private readonly string $reply, private readonly ?\Throwable $throw) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->history = $history;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                throw new \LogicException('not used by the background runner');
            }
        };
    }

    /**
     * @return array{0: Chat, 1: mixed}
     */
    private function submitLine(Chat $chat, string $line): array
    {
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            [$chat] = $chat->update(
                $char === ' ' ? new KeyMsg(KeyType::Space, '') : new KeyMsg(KeyType::Char, $char),
            );
        }
        if ($chat->slashMenuMatches() !== []) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    private function resolveAsyncCmd(\Closure $cmd): mixed
    {
        $resolved = null;
        $cmd()->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function removeTree(string $path): void
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
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

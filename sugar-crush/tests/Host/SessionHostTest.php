<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\TurnTicket;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap O-2g: {@see SessionHost} drives a session without a screen through
 * the same {@see TurnController} and {@see \SugarCraft\Crush\Host\TurnRunner}
 * the TUI uses — admission, queueing, dispatch, the durable story in the
 * event log, cancel, and the queue released when a turn settles.
 */
final class SessionHostTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-session-host-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sub);
        }
        @rmdir($this->dir);
    }

    public function testAnIdlePromptStartsATurnAndItsReplyLands(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);

        $ticket = $host->submit('hello', SubmitOptions::new()->withIdempotencyKey('k1'));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertSame('k1', $ticket->idempotencyKey);
        self::assertNotNull($ticket->turnId);
        self::assertTrue($host->isBusy());
        self::assertSame(['hello'], self::texts($backend->sent));

        $backend->settle(Message::assistant('hi there'));

        self::assertFalse($host->isBusy());
        self::assertSame(['hello', 'hi there'], self::texts($host->history()));
        self::assertSame(['hello', 'hi there'], self::texts(TranscriptStore::new($this->store)->load('s')), 'saved');
    }

    public function testTheDurableStoryStartsWithThePromptsMessageCreated(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);
        $heard = [];
        $detach = $host->onEvent(static function (SessionEvent $event) use (&$heard): void {
            $heard[] = $event->type;
        });

        $ticket = $host->submit('ship it');
        ($backend->emit)(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
        self::assertSame(1, $host->pump(), 'a live event folds on the host\'s tick');
        ($backend->emit)(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'a.txt')));
        $backend->settle(Message::assistant('done'));
        $detach();

        $types = array_map(static fn (array $row): string => $row['type'], EventLog::new($this->store)->since('s'));
        self::assertSame(
            [TurnController::MESSAGE_CREATED, 'turn.started', 'tool.started', 'tool.finished', 'assistant.completed', 'turn.completed'],
            $types,
        );
        $created = EventLog::new($this->store)->since('s')[0]['payload'];
        self::assertSame($ticket->messageId, $created['messageId']);
        self::assertContains('turn.started', $heard);
        self::assertContains('turn.completed', $heard);
        self::assertSame('a.txt', $host->history()[1]->content, 'the placeholder was replaced by the finished row');
    }

    public function testMidTurnASteerIsQueuedWhenTheBackendCannotTakeOneAndGoesOutAfterTheSettle(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);
        $host->submit('first');

        $steered = $host->submit('and also this');
        self::assertSame(TurnTicket::QUEUED, $steered->admitted, 'a backend that is not an InteractiveTurn takes no steer');
        self::assertSame(1, $steered->position);

        $queued = $host->submit('later', SubmitOptions::new()->withDelivery(QueueMode::Followup));
        self::assertSame(2, $queued->position);
        self::assertSame(['and also this', 'later'], $host->queued());

        $refused = $host->submit('/clear');
        self::assertSame(TurnTicket::REFUSED, $refused->admitted);
        self::assertStringContainsString('commands do not run while a turn is in flight', (string) $refused->reason);

        $backend->settle(Message::assistant('one'));

        self::assertSame(['and also this'], self::texts([$backend->sent[\count($backend->sent) - 1]]), 'the queue head went out as the next turn');
        self::assertSame(['later'], $host->queued());
        self::assertTrue($host->isBusy());
    }

    public function testInterruptCancelsTheRunningTurnAndSendsNow(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);
        $host->submit('slow one');
        ($backend->emit)(new ToolStarted('c9', 'Bash', ['command' => 'sleep 99']));
        $host->pump();

        $ticket = $host->submit('do this instead', SubmitOptions::new()->withDelivery(QueueMode::Interrupt));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertSame(Chat::CANCELLED_TOOL_CALL, $host->history()[1]->content, 'the running row was healed, not left spinning');
        self::assertSame('do this instead', $host->history()[\count($host->history()) - 1]->content);
    }

    public function testCancelStrandsTheTurnsLateReply(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);
        $host->submit('go');

        self::assertFalse($host->cancel('t_not_this_one'));
        self::assertTrue($host->cancel());
        self::assertFalse($host->isBusy());
        $backend->settle(Message::assistant('too late'));

        self::assertSame(['go'], self::texts($host->history()), 'a cancelled turn\'s reply never lands');
    }

    public function testWhatAHeadlessSessionRefuses(): void
    {
        $host = $this->host(self::scripted());

        self::assertSame(TurnTicket::REFUSED, $host->submit('   ')->admitted);
        // Roadmap O-2h: built-ins run headless now; the ones whose body is a
        // screen (or has not left Chat yet) say so — see SessionHostCommandsTest.
        self::assertStringContainsString('runs in the TUI client only', (string) $host->submit('/compact')->reason);
        self::assertStringContainsString('runs in the TUI client only', (string) $host->submit('/theme dark')->reason);
        self::assertFalse($host->isBusy());

        $noBackend = SessionHost::new('s', WorkspaceContext::new(sessionStore: $this->store));
        self::assertStringContainsString('no model backend', (string) $noBackend->submit('hello')->reason);
    }

    public function testACommandFileIsExpandedAndItsMentionsAreNotRead(): void
    {
        $root = $this->dir . '/project';
        mkdir($root, 0700);
        file_put_contents($root . '/secret.txt', 'top secret');
        $backend = self::scripted();
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend),
            customCommands: [
                'review' => CommandSpec::new('review', 'd', 'Custom', template: 'Review $ARGUMENTS'),
                'empty' => CommandSpec::new('empty', 'd', 'Custom', template: '$ARGUMENTS'),
            ],
        );

        self::assertSame(TurnTicket::REFUSED, $host->submit('/empty')->admitted, 'an expansion of nothing is refused, not sent');
        self::assertSame(TurnTicket::STARTED, $host->submit('/review @' . $root . '/secret.txt')->admitted);
        self::assertStringStartsWith('Review ', $backend->sent[0]->content);
        self::assertSame([], $backend->sent[0]->attachments, 'a command file\'s expansion is never read for mentions (15b-15)');
    }

    public function testATurnHookBlocksAndNotesRideBesideThePrompt(): void
    {
        $backend = self::scripted();
        $blocking = $this->host($backend, [self::hook(HookEvent::UserPromptSubmit, HookResult::deny('frozen'))]);
        $refused = $blocking->submit('deploy');
        self::assertSame(TurnTicket::REFUSED, $refused->admitted);
        self::assertStringContainsString('frozen', (string) $refused->reason);
        self::assertSame([], $backend->sent);

        $noting = $this->host($backend, [
            self::hook(HookEvent::SessionStart, HookResult::allow('', 'session note')),
            self::hook(HookEvent::UserPromptSubmit, HookResult::allow('', 'prompt note')),
        ]);
        $noting->submit('deploy');
        self::assertSame(['session note', 'prompt note', 'deploy'], self::texts($noting->history()));
        self::assertSame(Role::System, $noting->history()[0]->role);
    }

    public function testTheSpendCapRefusesTheNextTurn(): void
    {
        $backend = self::scripted();
        $host = SessionHost::new('s', WorkspaceContext::new(sessionStore: $this->store, backend: $backend, maxCostUsd: 0.01));

        $host->submit('expensive');
        $backend->settle(Message::assistant('done')->withUsage(Usage::new(totalTokens: 10, costUsd: 0.05)));

        self::assertGreaterThan(0.0, $host->spentUsd());
        $ticket = $host->submit('again');
        self::assertSame(TurnTicket::REFUSED, $ticket->admitted);
    }

    /**
     * Roadmap N-P4b on server sessions: `compaction.mode: off` skips the
     * automatic tier but still refuses a prompt the window cannot take, and
     * the thrash breaker counts to the session's `compaction.refillLimit`.
     */
    public function testCompactionModeOffRefusesWhatTheWindowCannotTakeWithoutCompacting(): void
    {
        $backend = self::scripted();
        $history = [Message::user(str_repeat('word ', 100_000)), Message::assistant('ok')];
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend),
            history: $history,
            compactorConfig: \SugarCraft\Crush\Context\CompactorConfig::new()->withMode('off'),
            customCommands: [],
        );

        $ticket = $host->submit('next');

        self::assertSame(TurnTicket::REFUSED, $ticket->admitted);
        self::assertStringStartsWith(\SugarCraft\Crush\Host\CompactionService::BLOCKED_TURN_PREFIX, (string) $ticket->reason);
        self::assertSame([], $backend->sent, 'nothing was sent');
        self::assertSame(self::texts($history), self::texts($host->history()), 'and nothing was compacted');
    }

    public function testTheThrashBreakerCountsToTheSessionsRefillLimit(): void
    {
        $backend = self::scripted();
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend),
            history: [Message::user(str_repeat('word ', 90_000)), Message::assistant('ok')],
            compactorConfig: \SugarCraft\Crush\Context\CompactorConfig::new()->withRefillLimit(5),
            customCommands: [],
        );
        (new \ReflectionProperty(SessionHost::class, 'consecutiveRefills'))->setValue($host, 5);

        $ticket = $host->submit('next');

        self::assertSame(TurnTicket::REFUSED, $ticket->admitted);
        self::assertStringContainsString('has run 5 times in a row', (string) $ticket->reason);
        self::assertSame([], $backend->sent);
    }

    public function testTheSnapshotIsWhatAnAttachingClientNeeds(): void
    {
        $backend = self::scripted();
        $host = $this->host($backend);
        $host->submit('one');
        $host->submit('two');

        $snapshot = $host->snapshot();
        self::assertSame('s', $snapshot->sessionId);
        self::assertSame('busy', $snapshot->status());
        self::assertSame(['two'], $snapshot->queued);
        self::assertSame($host->turnId(), $snapshot->turnId);
        self::assertGreaterThan(0, $snapshot->lastSeq, 'a client follows the log from here');
        self::assertSame('one', $snapshot->toArray()['messages'][0]['content']);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * @param list<HookInterface> $hooks
     */
    private function host(Backend $backend, array $hooks = []): SessionHost
    {
        $manager = null;
        if ($hooks !== []) {
            $registry = new HookRegistry();
            foreach ($hooks as $hook) {
                $registry->register($hook);
            }
            $manager = new HookManager($registry);
        }

        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend, hooks: $manager),
            customCommands: [],
        );
    }

    private static function hook(HookEvent $event, HookResult $verdict): HookInterface
    {
        return new class ($event, $verdict) implements HookInterface {
            public function __construct(private readonly HookEvent $hookEvent, private readonly HookResult $verdict)
            {
            }

            public function name(): string
            {
                return 'test-' . $this->hookEvent->value;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->verdict;
            }
        };
    }

    /**
     * @param list<Message> $rows
     * @return list<string>
     */
    private static function texts(array $rows): array
    {
        return array_map(static fn (Message $m): string => $m->content, $rows);
    }

    /**
     * A backend whose turn settles when the test says so.
     */
    private static function scripted(): object
    {
        return new class () implements Backend {
            /** @var list<Message> the newest user row of each dispatch */
            public array $sent = [];

            public ?\Closure $emit = null;

            private ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                for ($i = \count($history) - 1; $i >= 0; $i--) {
                    if ($history[$i]->role === Role::User) {
                        $this->sent[] = $history[$i];
                        break;
                    }
                }
                $this->deferred = new Deferred();
                $this->emit = static fn (object $event) => $onEvent === null ? null : $onEvent($event);

                return $this->deferred->promise();
            }

            public function settle(Message $reply): void
            {
                $deferred = $this->deferred;
                $this->deferred = null;
                $deferred?->resolve($reply);
            }
        };
    }
}

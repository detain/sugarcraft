<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Host\Commands\CommandEffectKind;
use SugarCraft\Crush\Host\Commands\CommandResult;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Host\TurnTicket;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Roadmap O-2h: a headless {@see SessionHost} runs the built-in slash
 * commands through the same `Host\Commands` bodies the TUI's handlers now
 * delegate to, runs a `!cmd` as the TUI does, and answers a screen-only
 * command with `CommandResult::clientOnly()` (`-32030` over the wire).
 */
final class SessionHostCommandsTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-host-commands-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    public function testABuiltInRunsHeadlessAndItsRowsAreSaved(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);
        $host = $this->host(gate: $gate);

        $ticket = $host->submit('/permissions');

        self::assertSame(TurnTicket::HANDLED, $ticket->admitted);
        self::assertFalse($host->isBusy(), 'a report holds nothing');
        $rows = $host->history();
        self::assertCount(2, $rows);
        self::assertSame('/permissions', $rows[0]->content);
        self::assertTrue($rows[0]->uiOnly && $rows[1]->uiOnly, 'a local report is never sent to the model');
        self::assertStringContainsString('Permission mode: plan', $rows[1]->content);

        $host->release();
        $saved = array_map(static fn (array $m): string => (string) $m['content'], $this->store->loadTranscript('s') ?? []);
        self::assertSame(['/permissions', $rows[1]->content], $saved, 'the exchange is persisted');
    }

    public function testTheTuiAndTheHostAnswerOneCommandWithTheSameRows(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook($gate));
        [$chat] = (new Chat(inputBuf: '/permissions', backend: new EchoBackend(), hooks: $hooks))
            ->withSize(100, 30)
            ->update(new KeyMsg(KeyType::Enter));

        $host = $this->host(gate: $gate);
        $host->submit('/permissions');

        self::assertSame(
            self::rows($chat->history),
            self::rows($host->history()),
            'one command body, two drivers: the rows cannot differ',
        );
    }

    public function testClearEmptiesTheTranscriptWithoutAnEcho(): void
    {
        $host = $this->host(history: [Message::user('one'), Message::assistant('two')]);

        self::assertSame(TurnTicket::HANDLED, $host->submit('/clear')->admitted);
        self::assertSame([], $host->history());
    }

    public function testRunCommandIsTheWireDoorAndNeverSendsProse(): void
    {
        $host = $this->host();

        $result = $host->runCommand('notices');
        self::assertFalse($result->isRefused());
        self::assertSame('/notices', $result->rows[0]->content);
        self::assertSame(['rows', 'effects'], array_keys($result->toArray()));

        $unknown = $host->runCommand('definitely-not-a-command', 'hello');
        self::assertTrue($unknown->isRefused());
        self::assertSame([], $unknown->rows);
        self::assertCount(2, $host->history(), 'a name no built-in answers to is refused, not sent');
    }

    public function testAScreenOnlyCommandIsClientOnly(): void
    {
        $host = $this->host();

        $result = $host->runCommand('theme', 'dark');
        self::assertTrue($result->isClientOnly());
        self::assertSame(CommandResult::CLIENT_ONLY, $result->toArray()['error']['code']);

        // A bare `/rename` opens the TUI's inline editor: a screen, so the same.
        self::assertTrue($host->runCommand('rename')->isClientOnly());
        self::assertSame(TurnTicket::REFUSED, $host->submit('/keys')->admitted);
        self::assertSame([], $host->history());
    }

    public function testAWordNoBuiltInAnswersToIsAPrompt(): void
    {
        $host = $this->host();

        self::assertSame(TurnTicket::STARTED, $host->submit('/usr/bin is on my PATH')->admitted);
    }

    public function testACommandWaitsForTheTurnItWouldRewrite(): void
    {
        $host = $this->host(backend: new class () implements \SugarCraft\Crush\Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return (new \React\Promise\Deferred())->promise();
            }
        });
        $host->submit('start a turn');

        $result = $host->runCommand('clear');
        self::assertTrue($result->isRefused());
        self::assertStringContainsString('commands do not run while a turn is in flight', (string) $result->error);
        self::assertNotSame([], $host->history());
    }

    public function testTheLedgerCommandsKeepTheSessionsLedger(): void
    {
        $host = $this->host();

        $host->submit('/pruning manual');
        $host->submit('/pruning');

        $rows = $host->history();
        self::assertStringContainsString('manual', $rows[\count($rows) - 1]->content);
    }

    public function testABangCommandRunsHoldsTheSessionAndLandsItsRow(): void
    {
        $host = $this->host(root: $this->project());

        $ticket = $host->submit('!echo headless-bang');

        self::assertSame(TurnTicket::HANDLED, $ticket->admitted);
        self::assertTrue($host->isBusy(), 'the command occupies the session until its row lands');
        self::assertSame(TurnTicket::QUEUED, $host->submit('after it')->admitted);

        $this->drain($host);

        $contents = array_map(static fn (Message $m): string => $m->content, $host->history());
        $result = array_values(array_filter($contents, static fn (string $c): bool => str_contains($c, 'headless-bang')
            && str_starts_with($c, '[The user ran a shell command')));
        self::assertCount(1, $result);
        self::assertStringContainsString('Exit code: 0', $result[0]);
        self::assertSame(Role::User, $host->history()[array_search($result[0], $contents, true)]->role);
    }

    public function testADeniedBangIsRefusedWithItsReason(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);
        $host = $this->host(gate: $gate, root: $this->project());

        $ticket = $host->submit('!rm -rf build');

        self::assertSame(TurnTicket::REFUSED, $ticket->admitted);
        self::assertStringContainsString('plan mode', (string) $ticket->reason);
        self::assertFalse($host->isBusy());
    }

    public function testAWorkflowRunHoldsTheSessionUntilItsReportLands(): void
    {
        $engine = new class () implements WorkflowEngineInterface {
            public function run(string $workflowPath, array $context = [], ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                \Fiber::suspend();

                return new WorkflowResult('wf-1', WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
            }

            public function resume(string $workflowId, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult($workflowId, WorkflowStatus::Completed);
            }

            public function getStatus(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Running;
            }

            public function listWorkflows(): array
            {
                return ['deploy'];
            }
        };
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: new EchoBackend(), workflowEngine: $engine),
            customCommands: [],
        );

        self::assertSame(TurnTicket::HANDLED, $host->submit('/workflow run deploy')->admitted);
        self::assertTrue($host->isBusy(), 'a workflow run is a turn');
        self::assertSame(TurnTicket::QUEUED, $host->submit('after the run')->admitted);

        // `/workflow status` may run inside the run it controls (audit WF-4) …
        self::assertSame(TurnTicket::HANDLED, $host->submit('/workflow status wf-1')->admitted);
        self::assertTrue($host->isBusy(), '… and leaves it holding the session');
        // … every other command still waits.
        self::assertTrue($host->runCommand('clear')->isRefused());

        $this->drain($host, static fn (SessionHost $h): bool => str_contains(
            implode("\n", array_map(static fn (Message $m): string => $m->content, $h->history())),
            "Workflow 'deploy' completed",
        ));

        $contents = array_map(static fn (Message $m): string => $m->content, $host->history());
        self::assertSame('/workflow run deploy', $contents[0]);
        self::assertStringContainsString('status: **running**', $contents[2]);
        self::assertStringContainsString("Workflow 'deploy' completed", implode("\n", $contents));
    }

    public function testBranchAndRenameRunHeadlessAgainstTheStore(): void
    {
        $host = $this->host();

        $branch = $host->runCommand('branch');
        self::assertStringStartsWith('Branch created: ', $branch->rows[1]->content);
        self::assertNotNull($branch->effect(CommandEffectKind::SwitchSession));
        self::assertSame('s', $host->sessionId(), 'a host keeps its session; the branch is the client\'s to open');

        $renamed = $host->runCommand('rename', 'Release prep');
        self::assertSame("Session renamed to 'Release prep'", $renamed->rows[1]->content);
    }

    public function testRewindRestoresTheCheckpointAndOffersItsDraftBack(): void
    {
        $host = $this->host();
        self::assertSame(TurnTicket::STARTED, $host->submit('first prompt')->admitted);
        self::assertFalse($host->isBusy(), 'the echo backend answered');

        $result = $host->runCommand('rewind');

        $restore = $result->effect(CommandEffectKind::RestoreCheckpoint);
        self::assertNotNull($restore, (string) ($result->rows[1]->content ?? ''));
        self::assertSame('first prompt', $restore->draft());
        $contents = array_map(static fn (Message $m): string => $m->content, $host->history());
        self::assertSame('/rewind', $contents[0], 'the turn is gone; the exchange that removed it stays');
        self::assertStringContainsString('Rewound 2 messages', $contents[1]);
    }

    public function testMemoryRunsHeadlessThroughTheHomeStore(): void
    {
        mkdir($this->dir . '/memory', 0700);
        $home = new MemoryStore($this->dir . '/memory');
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(root: $this->project(), sessionStore: $this->store, backend: new EchoBackend(), memoryStore: $home),
            customCommands: [],
        );

        $host->runCommand('memory', 'add remember the deploy key rotation --scope user');
        $list = $host->runCommand('memory', 'list user');

        self::assertStringContainsString('remember the deploy key rotation', $list->rows[1]->content);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * @param list<Message> $history
     */
    private function host(
        ?PermissionGate $gate = null,
        array $history = [],
        ?string $root = null,
        ?\SugarCraft\Crush\Backend $backend = null,
    ): SessionHost {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $root,
                sessionStore: $this->store,
                permissionGate: $gate,
                backend: $backend ?? new EchoBackend(),
            ),
            history: $history,
            customCommands: [],
        );
    }

    /** A project root beside (never around) the session store. */
    private function project(): string
    {
        $root = $this->dir . '/project';
        if (!is_dir($root)) {
            mkdir($root, 0700);
        }

        return $root;
    }

    /**
     * Run the shared loop until $done says so — by default, until $host lets
     * go of the session — bounded.
     *
     * @param (\Closure(SessionHost): bool)|null $done
     */
    private function drain(SessionHost $host, ?\Closure $done = null): void
    {
        $done ??= static fn (SessionHost $h): bool => !$h->isBusy();
        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop, $done): void {
            if ($done($host)) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertTrue($done($host), 'the off-loop work settled');
    }

    /**
     * @param list<Message> $rows
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function rows(array $rows): array
    {
        return array_map(static fn (Message $m): array => [$m->role->value, $m->content, $m->uiOnly], $rows);
    }
}

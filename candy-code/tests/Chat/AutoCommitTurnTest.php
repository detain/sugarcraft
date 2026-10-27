<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\ContextMeasuredMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\TurnSettleWork;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Workspace\AutoCommittedMsg;
use SugarCraft\Code\Workspace\AutoCommitter;

/**
 * Step 3.G, `autoCommit: turn`: when a turn settles, the files it changed are
 * committed in one commit whose subject the cheap title model writes from the
 * diff; the call is accounted and one display-only line says what happened.
 *
 * @see Chat::scheduleAutoCommit()
 */
final class AutoCommitTurnTest extends TestCase
{
    use CheckpointedRepoTrait;

    private string|false $suggestions = false;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        // One Cmd per settle, so the test can resolve it: no prompt suggestion.
        $this->suggestions = getenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS');
        putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false ? putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS') : putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        $this->tearDownCheckpointedRepo();
    }

    public function testASettledTurnIsCommittedWithTheTitleModelsSubject(): void
    {
        $this->turnCheckpoint([], 'add a greeting');
        file_put_contents($this->repo . '/hello.txt', "hello\n");
        $title = $this->titleBackend("feat: add a greeting file\n");

        [$settled, $cmd] = $this->chat('turn', $title)->update(new AssistantMsg(Message::assistant('Added hello.txt.')));

        self::assertInstanceOf(\Closure::class, $cmd);
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(AutoCommittedMsg::class, $landed);
        self::assertSame('feat: add a greeting file', $this->gitAt('log', '-1', '--format=%s'));
        self::assertCount(1, $title->asked);
        self::assertStringContainsString('<context>' . "\nthe prompt\n" . '</context>', $title->asked[0][1]->content);
        self::assertStringContainsString('new file hello.txt', $title->asked[0][1]->content);

        [$shown] = $settled->update($landed);
        $last = $shown->history[\count($shown->history) - 1];
        self::assertTrue($last->uiOnly);
        self::assertStringStartsWith('Auto-committed ' . substr($this->gitAt('rev-parse', 'HEAD'), 0, 7) . ' feat: add a greeting file — 1 file.', $last->content);
        self::assertEqualsWithDelta(0.001, $shown->spentUsd(), 1e-9, 'the title model\'s call is accounted');
    }

    public function testWithNoTitleModelThePlainSubjectIsUsed(): void
    {
        $this->turnCheckpoint([], 'change it');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        [, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertInstanceOf(AutoCommittedMsg::class, $this->resolve($cmd));
        self::assertSame('chore: update file.txt', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testATurnThatChangedNothingOrAnotherModeCommitsNothing(): void
    {
        $this->turnCheckpoint([], 'just talk');
        [, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Nothing to change.')));
        self::assertNull($this->resolve($cmd));

        file_put_contents($this->repo . '/file.txt', "changed\n");
        foreach (['off', 'edit', 'sometimes'] as $mode) {
            [, $none] = $this->chat($mode, null)->update(new AssistantMsg(Message::assistant('Done.')));
            // P-CTX.d: the mode still schedules no commit pass — what settles
            // now is the context remeasure ALONE, answering with its repaint
            // nudge. The commit silence is pinned by the head stays 'base'.
            self::assertNotNull($none, "autoCommit: {$mode} settles with the remeasure pass");
            self::assertInstanceOf(ContextMeasuredMsg::class, $this->resolve($none));
        }
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testAFailedCommitIsReportedNotHidden(): void
    {
        file_put_contents($this->repo . '/.git/hooks/pre-commit', "#!/bin/sh\necho 'tests failed' >&2\nexit 1\n");
        chmod($this->repo . '/.git/hooks/pre-commit', 0o755);
        $this->turnCheckpoint([], 'change it');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        [$settled, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Done.')));
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(AutoCommittedMsg::class, $landed);

        [$shown] = $settled->update($landed);
        self::assertStringStartsWith('Auto-commit skipped: git commit failed: tests failed', $shown->history[\count($shown->history) - 1]->content);
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    /**
     * C-CTX-2.e parity: the settle no longer keeps its own gates — it asks
     * Host\TurnSettleWork::plan(), the same table a served host consults
     * (C-CTX-2.c). Two access paths of one table must agree for one set of
     * inputs: the passes this TUI settle actually runs are exactly the slots
     * the table schedules when fed the same flags. (turnCall's gate is a
     * pure read, so planning before the commit changes the repo is honest.)
     */
    public function testTheSharedTableSchedulesTheSameSetTheTuiSettleRuns(): void
    {
        $this->turnCheckpoint([], 'change it');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        $chat = $this->chat('turn', null);
        $work = TurnSettleWork::plan(
            carriedOn: false,
            goalActive: false,
            memoryStore: null,
            engineBackend: new EchoBackend(),
            summaryBackend: null,
            titleBackend: null,
            history: $chat->history,
            capReached: false,
            sessionId: 'undo-session',
            generation: 0,
            projectRoot: $this->repo,
            userConfig: [AutoCommitter::SETTINGS_KEY => 'turn'],
            sessionStore: $this->store,
        );
        self::assertSame(['autoCommit'], \array_keys(\array_filter([
            'suggestion' => $work->suggestion,
            'consolidation' => $work->consolidation,
            'autoCommit' => $work->autoCommit,
            'dream' => $work->dream,
        ])), 'the table schedules the auto-commit pass alone');

        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant('Done.')));
        self::assertSame([AutoCommittedMsg::class], $this->settleScheduledPasses($cmd), 'the TUI settle runs the same set');

        $off = TurnSettleWork::plan(
            carriedOn: false,
            goalActive: false,
            memoryStore: null,
            engineBackend: new EchoBackend(),
            summaryBackend: null,
            titleBackend: null,
            history: $settled->history,
            capReached: false,
            sessionId: 'undo-session',
            generation: 0,
            projectRoot: $this->repo,
            userConfig: [AutoCommitter::SETTINGS_KEY => 'off'],
            sessionStore: $this->store,
        );
        self::assertSame([], \array_keys(\array_filter([
            'suggestion' => $off->suggestion,
            'consolidation' => $off->consolidation,
            'autoCommit' => $off->autoCommit,
            'dream' => $off->dream,
        ])), 'and the stand-downs match too: `off` commits on neither path');
    }

    /**
     * The passes the settle actually scheduled: every child of the batch it
     * hands the loop, resolved, minus the context-remeasure stamp that rides
     * beside them.
     *
     * @return list<class-string>
     */
    private function settleScheduledPasses(?\Closure $cmd): array
    {
        self::assertNotNull($cmd);
        $batch = $cmd();
        $types = [];

        foreach ($batch instanceof BatchMsg ? $batch->cmds : [$batch] as $child) {
            $async = $child instanceof AsyncCmd ? $child : $child();
            self::assertInstanceOf(AsyncCmd::class, $async);
            $landed = null;
            $async->promise->then(static function (mixed $msg) use (&$landed): void {
                $landed = $msg;
            });

            if ($landed !== null && !$landed instanceof ContextMeasuredMsg) {
                $types[] = $landed::class;
            }
        }

        return $types;
    }

    private function chat(string $mode, ?Backend $title): Chat
    {
        return new Chat(
            history: [Message::user('the prompt')],
            inFlight: true,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 'undo-session',
            currentSessionName: 'named',
            projectRoot: $this->repo,
            titleBackend: $title,
            workspace: WorkspaceContext::new(root: $this->repo, userConfig: [AutoCommitter::SETTINGS_KEY => $mode]),
        );
    }

    private function resolve(?\Closure $cmd): mixed
    {
        self::assertNotNull($cmd);
        $async = $cmd();
        // P-CTX.d: a settle that schedules more than one pass batches them; the
        // first child is the pass under test, the remeasure rides beside it.
        if ($async instanceof BatchMsg) {
            self::assertCount(2, $async->cmds);
            $async = ($async->cmds[0])();
        }
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function titleBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}

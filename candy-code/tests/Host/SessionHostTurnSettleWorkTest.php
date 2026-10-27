<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Workspace\AutoCommitter;

use function React\Promise\resolve;

/**
 * C-CTX-2.c: a settled turn in a SERVED session runs the same side-calls the
 * TUI's settle does — auto-memory consolidation, the `turn`-mode auto-commit
 * and the dream pass — decided by the shared {@see \SugarCraft\Code\Host\TurnSettleWork}
 * table and landed through this host's own channels: billed first on the rule
 * OBS-3 pins (the model of the engine that actually ran the call), then one
 * display-only notice row from the producer, and a landing after release()
 * billed but dropped. The next-prompt suggestion is deliberately never run
 * here — a served session has no input box to show it in.
 *
 * The pins: a settle schedules consolidation, lands its notice, and bills the
 * summary model's cost under the summary model; a queued prompt carries the
 * conversation on and suppresses every settle-work call until the last turn
 * actually settles; and `autoCommit: turn` commits the turn's write through
 * the checkpoint's workspace snapshot.
 */
final class SessionHostTurnSettleWorkTest extends TestCase
{
    use CheckpointedRepoTrait;

    /** The consolidator's canned answer: one saved note, nothing skipped. */
    private const OPS_REPLY = '{"operations":[{"op":"add","scope":"user","type":"preference","content":"Deploys go through bin/deploy, never kubectl.","tags":["deploy"]}]}';

    /** @var list<SessionHost> */
    private array $hosts = [];

    private string|false $suggestions = false;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        // The schedulers throttle on a file inside the memory store, and the
        // auto-commit subject may fall back to the title service: clear any
        // ambient opt-outs so these pins answer on the settle's own flags
        // (AutoMemoryConsolidatorTest + AutoCommitTurnTest precedent).
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->suggestions = getenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS');
        putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->release();
        }
        $this->hosts = [];
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->suggestions === false ? putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS') : putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        $this->tearDownCheckpointedRepo();
    }

    public function testAServedSettleRunsAutoMemoryBillsItOnTheSummaryModelAndShowsItsNotice(): void
    {
        $memoryDir = $this->tmp . '/mem-pin1';
        self::assertIsBool(mkdir($memoryDir, 0700, true));
        $memory = new MemoryStore($memoryDir);
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['done']),
                memoryStore: $memory,
                summaryBackend: $this->engine([new CompleteResponse(content: self::OPS_REPLY, usage: Usage::new(42, 0.003))], 'sum-model'),
            ),
            $this->seedConversation(),
        );

        self::assertSame(TurnTicket::STARTED, $host->submit('ship it')->admitted);
        $this->drainWork($host);
        // The consolidation turn itself forks (C-CTX-2.a's second window):
        // its promise settles on the loop AFTER the host idles, so spin
        // until the producer's notice row is what history last added.
        $this->drainWork($host, fn (): bool => $this->noticeRows($host, 'Auto-memory saved') !== []);

        $rows = $this->noticeRows($host, 'Auto-memory saved');
        self::assertCount(1, $rows, 'the settled serve said what auto-memory stored');
        self::assertStringStartsWith('Auto-memory saved 1 note', $rows[0]);

        // OBS-3 on serve: the call is billed under the model that RAN it.
        $byModel = [];
        foreach ($this->store->usageModelRollups('undo-session') as $rollup) {
            $byModel[(string) $rollup['model']] = $rollup;
        }
        self::assertArrayHasKey('m', $byModel, 'the turn billed under the session model');
        self::assertArrayHasKey('sum-model', $byModel, 'the consolidation billed under the SUMMARY model, not the session one');
        self::assertEqualsWithDelta(0.001, $byModel['m']['costUsd'], 1e-9);
        self::assertEqualsWithDelta(0.003, $byModel['sum-model']['costUsd'], 1e-9);
        self::assertEqualsWithDelta(0.004, $host->spentUsd(), 1e-9, 'both calls ride the session spend total');

        // The other slots answered themselves off: no memory journal to
        // dream over, auto-commit off. (TurnSettleWorkTest owns the gates.)
        self::assertSame([], $this->noticeRows($host, 'Dream pass'));
        self::assertSame([], $this->noticeRows($host, 'Auto-committed'));
    }

    public function testAQueuedPromptCarriesTheConversationOnAndSuppressesEverySettleCall(): void
    {
        $memoryDir = $this->tmp . '/mem-pin2';
        self::assertIsBool(mkdir($memoryDir, 0700, true));
        $memory = new MemoryStore($memoryDir);
        $summary = $this->recordingSummary();
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['first reply', 'second reply', 'first reply', 'second reply']),
                memoryStore: $memory,
                summaryBackend: $summary,
            ),
            $this->seedConversation(),
        );

        self::assertSame(TurnTicket::STARTED, $host->submit('keep going')->admitted);
        self::assertSame(
            TurnTicket::QUEUED,
            $host->submit('and then this', SubmitOptions::new()->withDelivery(QueueMode::Followup))->admitted,
            'a follow-up delivery parks the second prompt in the queue',
        );
        $this->drainWork($host, fn (): bool => !$host->isBusy() && $this->countRows($host, 'Auto-memory saved') === 1);

        self::assertSame(3, $this->assistantCount($host), 'both prompts got their turns, over the seeded assistant row');
        self::assertSame(1, $summary->asked, 'the consolidator was asked exactly once, not at the carried-on settle');
        $rows = $this->noticeRows($host, 'Auto-memory saved');
        self::assertCount(1, $rows, 'one notice for the one conversation-settling settle');
        self::assertGreaterThan(
            $this->lastAssistantIndex($host),
            $this->indexOfRow($host, $rows[0]),
            'the notice rides the settle of the turn the queued prompt STARTED — the conversation never settled before it',
        );
    }

    public function testTheTurnModeAutoCommitRunsOnAServedSettleAndShowsItsRow(): void
    {
        // The commit's author is the user: name them in the checkout, as
        // AutoCommitterTest does — the trail of refusals for a missing
        // identity belongs to that suite, not this wiring pin.
        $this->gitAt('config', 'user.name', 'Test User');
        $this->gitAt('config', 'user.email', 'test@example.invalid');

        $turn = new CompleteResponse(
            content: '',
            toolCalls: [new ToolCall('c1', 'Write', ['file_path' => 'note.txt', 'content' => 'changed by the turn', 'description' => 'note the decision'])],
        );
        $host = $this->host(
            WorkspaceContext::new(
                root: $this->repo,
                userConfig: [AutoCommitter::SETTINGS_KEY => AutoCommitter::MODE_TURN],
                sessionStore: $this->store,
                backend: $this->engine([$turn, 'done'])->withTools([new Write($this->repo)]),
            ),
            [Message::user('note the decision')],
        );

        self::assertSame(TurnTicket::STARTED, $host->submit('write the note')->admitted);
        $this->drainWork($host);
        $this->drainWork($host, fn (): bool => $this->noticeRows($host, 'Auto-committed') !== []);

        $rows = $this->noticeRows($host, 'Auto-committed');
        self::assertCount(1, $rows, 'the turn-mode auto-commit landed its row through the checkpointed snapshot');
        self::assertStringStartsWith('Auto-committed ', $rows[0], 'a skip row would say otherwise');
        self::assertCount(2, array_filter(explode("\n", trim($this->gitAt('log', '--format=%h')))), 'base plus the auto-commit');
        self::assertFileExists($this->repo . '/note.txt');
    }

    private function host(WorkspaceContext $workspace, array $history): SessionHost
    {
        $host = SessionHost::new('undo-session', $workspace, history: $history, customCommands: []);
        $this->hosts[] = $host;

        return $host;
    }

    /**
     * @param list<string|CompleteResponse> $answers
     */
    private function engine(array $answers, string $model = 'm'): EngineBackend
    {
        $script = array_map(
            static fn (string|CompleteResponse $answer): CompleteResponse
                => $answer instanceof CompleteResponse ? $answer : new CompleteResponse(content: $answer, usage: Usage::new(10, 0.001)),
            $answers,
        );

        return EngineBackend::new(new ScriptedProvider($script), $model)
            ->withoutHooks()
            ->withRoot($this->repo);
    }

    /**
     * A synchronous summary double: resolving inside the settle tail means a
     * broken suppression would land its notice mid-conversation, right where
     * the ordering pin looks. `asked` counts the actual requests.
     */
    private function recordingSummary(): Backend
    {
        return new class (self::OPS_REPLY) implements Backend {
            public int $asked = 0;

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                ++$this->asked;

                return new Message(Role::Assistant, $this->reply, 0, usage: Usage::new(totalTokens: 42, costUsd: 0.003));
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return resolve($this->complete($history));
            }
        };
    }

    /**
     * An assistant-terminated conversation past the consolidator's 400-byte
     * input floor, so the suppressed first settle would REALLY have scheduled
     * a call had the carried-on flag not stood it down.
     *
     * @return list<Message>
     */
    private function seedConversation(): array
    {
        $user = str_repeat('Please keep this in mind for later sessions. ', 6);
        $reply = str_repeat('I will remember the decision and its reason. ', 6);

        return [Message::user($user), Message::assistant($reply)];
    }

    /**
     * Spin the loop until $done answers (default: the host is idle) or the
     * bounded guard fires — a ceiling on the wait, never a product timeout.
     */
    private function drainWork(SessionHost $host, ?\Closure $done = null): void
    {
        $loop = Loop::get();
        $idle = static fn (): bool => !$host->isBusy();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop, $done, $idle): void {
            if (($done ?? $idle)()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(60.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);
    }

    /** @return list<string> */
    private function noticeRows(SessionHost $host, string $prefix): array
    {
        return array_values(array_filter(
            array_map(static fn (Message $m): string => $m->content, $host->history()),
            static fn (string $text): bool => str_starts_with($text, $prefix),
        ));
    }

    private function countRows(SessionHost $host, string $prefix): int
    {
        return count($this->noticeRows($host, $prefix));
    }

    private function assistantCount(SessionHost $host): int
    {
        return count(array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::Assistant,
        ));
    }

    private function lastAssistantIndex(SessionHost $host): int
    {
        $last = -1;
        foreach ($host->history() as $index => $message) {
            if ($message->role === Role::Assistant) {
                $last = $index;
            }
        }

        return $last;
    }

    private function indexOfRow(SessionHost $host, string $content): int
    {
        foreach ($host->history() as $index => $message) {
            if ($message->content === $content) {
                return $index;
            }
        }

        self::fail('row vanished from history: ' . $content);
    }
}

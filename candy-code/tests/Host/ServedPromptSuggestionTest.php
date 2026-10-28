<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Usage;

/**
 * C-CTX-2.d: a settled turn in a SERVED session runs the next-prompt
 * suggestion the TUI's settle already ran, and lands it as the live-only
 * `prompt.suggested` event instead of a transcript row — billed first on the
 * rule OBS-3 pins (the title engine's own model), sanitised to one line, and
 * dropped UNANNOUNCED when it lands past the conversation's current point,
 * exactly the drop the TUI's own arm performs. The companion `context.changed`
 * rides every settle, every typed compaction, and the results of the commands
 * that actually move what the prompt is built from — nothing else.
 *
 * The pins here: one `prompt.suggested` per settle with the sanitised text and
 * the generation it was planned under, and no trace of either event class in
 * the durable log; a late answer billed but not announced; the environment
 * off-switch never asks the title model at all; and the command roster is
 * bidirectional — `/rules` and `/memory` announce, a non-roster command does
 * not.
 */
final class ServedPromptSuggestionTest extends TestCase
{
    use CheckpointedRepoTrait;
    use HomeSandboxTrait;

    /** The env the consolidator throttles itself off (cleared like the sibling suite). */
    private const DISABLE_ENV = 'CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS';

    /** @var list<SessionHost> */
    private array $hosts = [];

    private string|false $suggestions = false;

    /** The sandboxed home {@see setUp()} points HOME at (rules live in it). */
    private string $sandboxHome = '';

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        $home = $this->tmp . '/home-suggestions';
        self::assertIsBool(mkdir($home . '/.candy-code', 0700, true));
        $this->useHomeSandbox($home, create: false);
        $this->sandboxHome = $home;
        // Empty settings ⇒ `promptSuggestions` default-on; the memo must not
        // carry the ambient home's answer across the swap.
        UiSettings::forget();
        // The consolidation scheduler is irrelevant here (memoryStore is null
        // in every fixture), but the sibling suite clears it for exactly the
        // same reason: these pins answer on the settle's own flags.
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->suggestions = getenv(self::DISABLE_ENV);
        putenv(self::DISABLE_ENV);
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->release();
        }
        $this->hosts = [];
        UiSettings::forget();
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        $this->suggestions === false ? putenv(self::DISABLE_ENV) : putenv(self::DISABLE_ENV . '=' . $this->suggestions);
        $this->restoreHomeSandbox();
        $this->tearDownCheckpointedRepo();
    }

    public function testAServedSettleAnnouncesTheSuggestionOnceWithoutPersistingIt(): void
    {
        $title = new ScriptedProvider([
            new CompleteResponse(
                content: "Ship the release\nsecond-line junk the model added",
                usage: Usage::new(20, 0.002),
            ),
        ]);
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['done']),
                titleBackend: EngineBackend::new($title, 'title-model')
                    ->withoutHooks()
                    ->withRoot($this->repo),
            ),
            $this->seedConversation(),
        );
        $heard = $this->collect($host);

        self::assertSame(TurnTicket::STARTED, $host->submit('ship it')->admitted);
        $this->drainWork($host, fn (): bool => $this->typed($heard, SessionEvent::PROMPT_SUGGESTED) !== []);
        $this->spin(0.2);

        $suggested = $this->typed($heard, SessionEvent::PROMPT_SUGGESTED);
        self::assertCount(1, $suggested, 'one suggestion per settle, and the extra beat grows none');
        self::assertSame(
            ['text' => 'Ship the release', 'generation' => 1],
            $suggested[0]->data,
            'the line is sanitised to its first row and carries the generation it was planned under',
        );
        self::assertSame(
            [['reason' => 'settle']],
            array_map(static fn (SessionEvent $e): array => $e->data, $this->typed($heard, SessionEvent::CONTEXT_CHANGED)),
            'the settle says what moved the context, once',
        );

        // NEVER persisted: neither the suggestion nor a context notice may be
        // a history row, and the durable log may not carry either event.
        foreach ($host->history() as $row) {
            self::assertStringNotContainsString('Ship the release', $row->content);
        }
        $logTypes = array_column($host->events()->since('undo-session'), 'type');
        self::assertNotContains(SessionEvent::PROMPT_SUGGESTED, $logTypes, 'a live-only event must not reach the session log');
        self::assertNotContains(SessionEvent::CONTEXT_CHANGED, $logTypes, 'a live-only event must not reach the session log');

        // OBS-3 on serve: the side-call is billed under the model that RAN it.
        $byModel = [];
        foreach ($this->store->usageModelRollups('undo-session') as $rollup) {
            $byModel[(string) $rollup['model']] = $rollup;
        }
        self::assertArrayHasKey('title-model', $byModel, 'the suggestion billed under the TITLE model');
        self::assertEqualsWithDelta(0.002, $byModel['title-model']['costUsd'], 1e-9);
        self::assertEqualsWithDelta(0.001, $byModel['m']['costUsd'], 1e-9);
        self::assertEqualsWithDelta(0.003, $host->spentUsd(), 1e-9, 'turn plus side-call ride the session spend total');
    }

    public function testASuggestionThatLandsLateIsBilledAndNotAnnounced(): void
    {
        $title = $this->heldTitle();
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['first reply', 'second reply', 'first reply', 'second reply']),
                titleBackend: $title,
            ),
            $this->seedConversation(),
        );
        $heard = $this->collect($host);

        self::assertSame(TurnTicket::STARTED, $host->submit('one')->admitted);
        $this->drainWork($host, fn (): bool => $title->asked === 1);
        self::assertSame(TurnTicket::STARTED, $host->submit('two')->admitted);
        $this->drainWork($host, fn (): bool => $title->asked === 2 && !$host->isBusy());

        // The first answer lands after the SECOND turn has already settled:
        // same session, older generation — the TUI drops it, and so does the
        // serve, after billing it. A billed-but-dropped cost is not a refund.
        $spentBefore = $host->spentUsd();
        $title->held[0]->resolve(new Message(Role::Assistant, 'Old guess', 0, usage: Usage::new(20, 0.002)));
        self::assertSame([], $this->typed($heard, SessionEvent::PROMPT_SUGGESTED), 'the stale answer never reaches a client');
        self::assertEqualsWithDelta($spentBefore + 0.002, $host->spentUsd(), 1e-9, 'stale is billed exactly like fresh');

        $title->held[1]->resolve(new Message(Role::Assistant, 'Fresh guess', 0, usage: Usage::new(20, 0.002)));
        $suggested = $this->typed($heard, SessionEvent::PROMPT_SUGGESTED);
        self::assertCount(1, $suggested, 'only the current answer is announced');
        self::assertSame(['text' => 'Fresh guess', 'generation' => 2], $suggested[0]->data);
        self::assertSame(
            ['settle', 'settle'],
            array_map(static fn (SessionEvent $e): string => (string) $e->data['reason'], $this->typed($heard, SessionEvent::CONTEXT_CHANGED)),
            'both settles spoke, stale drop or not',
        );
    }

    public function testEnvironmentOffNeverAsksTheTitleModelAndTheSettleStillAnnounces(): void
    {
        putenv(self::DISABLE_ENV . '=1');
        $title = new ScriptedProvider([
            new CompleteResponse(content: 'Should never be asked', usage: Usage::new(20, 0.002)),
        ]);
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['done']),
                titleBackend: EngineBackend::new($title, 'title-model')
                    ->withoutHooks()
                    ->withRoot($this->repo),
            ),
            $this->seedConversation(),
        );
        $heard = $this->collect($host);

        self::assertSame(TurnTicket::STARTED, $host->submit('ship it')->admitted);
        $this->drainWork($host);
        $this->spin(0.2);

        self::assertSame([], $title->requests, 'the off-switch refuses the side-call before the loop sees it');
        self::assertSame([], $this->typed($heard, SessionEvent::PROMPT_SUGGESTED));
        self::assertCount(1, $this->typed($heard, SessionEvent::CONTEXT_CHANGED), 'the switch hides the ghost text, not the context lane');
    }

    public function testContextChangedFollowsTheContextChangingCommandsOnly(): void
    {
        $memoryDir = $this->tmp . '/mem-cmds';
        self::assertIsBool(mkdir($memoryDir, 0700, true));
        self::assertIsBool(mkdir($this->sandboxHome . '/.candy-code/rules', 0700, true));
        file_put_contents($this->sandboxHome . '/.candy-code/rules/terse.md', 'Answer in one sentence where possible.');
        $host = $this->host(
            WorkspaceContext::new(
                userConfig: [],
                sessionStore: $this->store,
                backend: $this->engine(['done']),
                memoryStore: new MemoryStore($memoryDir),
            ),
            $this->seedConversation(),
        );
        $heard = $this->collect($host);

        // A roster name toggles/answers: both move what the next prompt is
        // built from. A roster-external command answers too, and says nothing.
        self::assertSame(0, $host->runCommand('rules', 'terse')->errorCode ?? 0, '/rules terse toggled');
        self::assertSame(0, $host->runCommand('memory', 'add keep the deploy key short --scope user')->errorCode ?? 0, '/memory add wrote');
        self::assertSame(0, $host->runCommand('notices')->errorCode ?? 0, '/notices answered off-roster');

        self::assertSame(
            ['rules', 'memory'],
            array_map(static fn (SessionEvent $e): string => (string) $e->data['reason'], $this->typed($heard, SessionEvent::CONTEXT_CHANGED)),
            'the roster announces by name, and nothing else speaks',
        );
        self::assertSame([], $this->typed($heard, SessionEvent::PROMPT_SUGGESTED), 'commands settle no side-call');
    }

    /**
     * A title double whose answers are held: `asked` counts the actual
     * requests, `held` lets the test pick the exact settle each promise
     * finally lands against.
     */
    private function heldTitle(): Backend
    {
        return new class () implements Backend {
            /** @var list<Deferred> */
            public array $held = [];

            public int $asked = 0;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return new Message(Role::Assistant, 'unreachable synchronously', 0, usage: Usage::new(20, 0.002));
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                ++$this->asked;
                $deferred = new Deferred();
                $this->held[] = $deferred;

                return $deferred->promise();
            }
        };
    }

    /**
     * Attach a live listener: the return is the SAME object the closure
     * appends to (an array return would copy the empty pre-state and every
     * later read would see nothing).
     *
     * @return \ArrayObject<int, SessionEvent>
     */
    private function collect(SessionHost $host): \ArrayObject
    {
        $heard = new \ArrayObject();
        $host->onEvent(static function (SessionEvent $event) use ($heard): void {
            $heard->append($event);
        });

        return $heard;
    }

    /** @param \ArrayObject<int, SessionEvent> $heard @return list<SessionEvent> */
    private function typed(\ArrayObject $heard, string $type): array
    {
        return array_values(array_filter(
            $heard->getArrayCopy(),
            static fn (SessionEvent $e): bool => $e->type === $type,
        ));
    }

    /** Let the loop service its watchers for a bounded beat. */
    private function spin(float $seconds = 0.05): void
    {
        $loop = Loop::get();
        $loop->addTimer($seconds, static fn () => $loop->stop());
        $loop->run();
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
     * An assistant-terminated conversation the suggestion gate accepts.
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
}

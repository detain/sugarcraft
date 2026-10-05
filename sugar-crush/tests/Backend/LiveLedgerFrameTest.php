<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Events\ContextLedgerChanged;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolResult as RowResult;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.B-3's remainder (DCP §13.2 G): a change the turn makes to its
 * context ledger reaches the host WHILE THE TURN RUNS. The engine shows each
 * one to `$onEvent` as a {@see ContextLedgerChanged} — on the forked path as
 * the live `ledger` frame — the {@see TurnRunner} applies it to the session's
 * ledger as it arrives, and the transcript reads that ledger
 * ({@see Chat::contextLedgerView()}) so the pruned row wears its badge before
 * the turn settles. And the model's `Prune` is a compaction: a PreCompact hook
 * that refuses compactions refuses it.
 */
final class LiveLedgerFrameTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testThePruneIsShownToTheObserverAsItLands(): void
    {
        $events = [];
        $this->engine(self::provider())->withContextLedger(self::autoLedger())->complete(
            [Message::user('read a.php, then tidy up')],
            onEvent: static function (object $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $changes = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ContextLedgerChanged));
        $this->assertCount(1, $changes, 'one change: the model\'s one Prune call');
        $this->assertSame('p1', $changes[0]->toolCallId, 'named by the call that made it');
        $this->assertCount(1, $changes[0]->delta->prunes);
        $this->assertSame('c1', $changes[0]->delta->prunes[0]->toolCallId);
        $this->assertSame(PruneAuthor::Model, $changes[0]->delta->prunes[0]->by);

        $order = array_map(static fn (object $e): string => $e::class, $events);
        $finishedPrune = array_search(\SugarCraft\Crush\Events\ToolFinished::class, array_reverse($order, true), true);
        $this->assertLessThan(array_search(ContextLedgerChanged::class, $order, true), $finishedPrune, 'shown once the call that made it finished');
    }

    public function testATurnWithoutAHostLedgerShowsNothing(): void
    {
        $events = [];
        $this->engine(new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000))->complete(
            [Message::user('hi')],
            onEvent: static function (object $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertSame([], array_filter($events, static fn (object $e): bool => $e instanceof ContextLedgerChanged));
    }

    public function testTheLedgerFrameCrossesTheForkIntact(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl and no frame crosses a socket');
        }

        $events = [];
        $reply = $this->drainUntilSettled(
            $this->engine(self::provider())->withContextLedger(self::autoLedger())->completeAsync(
                [Message::user('go')],
                onEvent: static function (object $event) use (&$events): void {
                    $events[] = $event;
                },
            ),
        );

        $this->assertInstanceOf(Message::class, $reply);
        $changes = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ContextLedgerChanged));
        $this->assertCount(1, $changes, 'the `ledger` frame decoded back into the event');
        $this->assertSame('p1', $changes[0]->toolCallId);
        $this->assertTrue($reply->contextLedger?->apply($changes[0]->delta) === $reply->contextLedger, 'idempotent with the ledger the reply carries');
    }

    public function testTheCodecRefusesAFrameWithNothingToApply(): void
    {
        $decode = new \ReflectionMethod(EngineBackend::class, 'decodeEvent');
        $encode = new \ReflectionMethod(EngineBackend::class, 'encodeEvent');
        $event = new ContextLedgerChanged(LedgerDelta::new()->withPrune(self::entry('c1')), 'p1');

        $frame = $encode->invoke(null, $event);
        $this->assertSame('ledger', $frame['kind']);
        // The parent reads frames with allowed_classes => false.
        $frame = unserialize(serialize($frame), ['allowed_classes' => false]);
        $this->assertEquals($event, $decode->invoke(null, $frame));

        $this->assertNull($decode->invoke(null, ['kind' => 'ledger', 'delta' => ['prunes' => [['garbage' => true]]]]));
        $this->assertNull($decode->invoke(null, ['kind' => 'ledger']));
    }

    public function testThePruneIsRefusedWhenAPreCompactHookRefusesCompactions(): void
    {
        $fired = new \ArrayObject();
        $provider = self::provider();
        $reply = $this->engine($provider)
            ->withHooks(self::hooks(HookResult::deny('archive first'), $fired))
            ->withContextLedger(self::autoLedger())
            ->complete([Message::user('go')]);

        $this->assertCount(1, $fired, 'the turn\'s PreCompact chain ran once, for the Prune call');
        $this->assertSame('PreCompact', $fired[0]->toolName);
        $this->assertSame(['trigger' => 'auto', 'custom_instructions' => ''], json_decode($fired[0]->toolInput, true));
        $this->assertStringContainsString('a PreCompact hook refused this prune (archive first)', (string) self::sent($provider->requests[2], 'p1'));
        $this->assertFalse((bool) $reply->contextLedger?->isPruned('c1'), 'nothing was pruned');
    }

    public function testAPermittingHookLetsThePruneLand(): void
    {
        $fired = new \ArrayObject();
        $reply = $this->engine(self::provider())
            ->withHooks(self::hooks(HookResult::allow(), $fired))
            ->withContextLedger(self::autoLedger())
            ->complete([Message::user('go')]);

        $this->assertCount(1, $fired);
        $this->assertTrue((bool) $reply->contextLedger?->isPruned('c1'));
    }

    public function testTheRunnerAppliesTheChangeToTheSessionAsItArrivesAndTheTranscriptBadgesTheRow(): void
    {
        $runner = TurnRunner::new();
        $inbox = new \ArrayObject();
        $seen = null;
        $backend = new class (static function () use ($runner, &$seen): void {
            $seen = $runner->heldLedger('s');
        }) implements Backend {
            public function __construct(private readonly \Closure $probe)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('done');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $onEvent?->__invoke(new ContextLedgerChanged(LedgerDelta::new()->withPrune(LiveLedgerFrameTest::entry('c1')), 'p1'));
                ($this->probe)();
                $deferred = new Deferred();
                $deferred->resolve(Message::assistant('done'));

                return $deferred->promise();
            }
        };

        $msg = $this->settle($runner->start($backend, [Message::user('go')], $inbox, 1, new CancellationToken(), false, sessionId: 's')());

        $this->assertTrue((bool) $seen?->isPruned('c1'), 'held the moment the frame arrived, before the turn settled');
        $this->assertInstanceOf(AssistantMsg::class, $msg, 'a live-only event is not folded again at the settle');
        $this->assertSame([], $inbox->getArrayCopy());
        $this->assertTrue((bool) $runner->ledger(null, 's')->isPruned('c1'), 'and kept as the session\'s');
        $this->assertNull($runner->heldLedger('other'));

        $runner->observeLedger(new CancellationToken(), new ContextLedgerChanged(LedgerDelta::new()->withPrune(self::entry('zz')), 'p9'));
        $this->assertFalse($runner->ledger(null, 's')->isPruned('zz'), 'a turn it did not start changes nothing');
    }

    public function testTheTranscriptReadsTheHeldLedger(): void
    {
        $inbox = new \ArrayObject();
        TurnRunner::of($inbox)->saveLedger(null, 's', ContextLedger::new()->withPrune(self::entry('c1')));
        $history = [
            Message::user('read it'),
            Message::assistant('')->withToolResults([new RowResult(name: 'Read', result: 'done', id: 'c1')]),
        ];

        $chat = new Chat(history: $history, backend: new EchoBackend(), currentSessionId: 's', liveToolEvents: $inbox, cols: 100, rows: 30);
        $this->assertTrue((bool) $chat->contextLedgerView()?->isPruned('c1'));
        $this->assertStringContainsString('⊟ pruned', self::plain(Renderer::render($chat)));

        $other = new Chat(history: $history, backend: new EchoBackend(), currentSessionId: 't', liveToolEvents: $inbox, cols: 100, rows: 30);
        $this->assertNull($other->contextLedgerView());
        $this->assertStringNotContainsString('⊟ pruned', self::plain(Renderer::render($other)), 'another session\'s ledger is not this one\'s');
    }

    public function testTheChatPumpTakesTheEventWithoutTouchingTheTranscript(): void
    {
        $inbox = new \ArrayObject();
        $chat = new Chat(history: [Message::user('go')], backend: new EchoBackend(), liveToolEvents: $inbox, cols: 100, rows: 30);
        $inbox[] = [0, new ContextLedgerChanged(LedgerDelta::new()->withPrune(self::entry('c1')), 'p1')];

        $pump = new \ReflectionMethod(Chat::class, 'pumpLiveToolEvents');
        [$next] = $pump->invoke($chat);

        $this->assertSame($chat->history, $next->history);
        $this->assertSame([], $inbox->getArrayCopy(), 'consumed');
    }

    // ── harness ─────────────────────────────────────────────────────────

    public static function entry(string $id): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 100);
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-live-ledger-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)->withTools([self::readTool(), Prune::new()]);
    }

    private static function autoLedger(): ContextLedger
    {
        return ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    private static function hooks(HookResult $verdict, \ArrayObject $fired): HookManager
    {
        $registry = new HookRegistry();
        $registry->register(new class ($verdict, $fired) implements HookInterface {
            public function __construct(private readonly HookResult $verdict, private readonly \ArrayObject $fired)
            {
            }

            public function name(): string
            {
                return 'precompact-recorder';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreCompact;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->fired[] = $context;

                return $this->verdict;
            }
        });

        return new HookManager($registry);
    }

    private static function provider(): ScriptedProvider
    {
        return new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r2']], 'reason' => 'done'])]),
            new CompleteResponse(content: 'tidied'),
        ], contextWindow: 1_000_000);
    }

    private static function sent(CompleteRequest $request, string $callId): ?string
    {
        foreach ($request->messages as $message) {
            if ($message instanceof ToolResultMessage && $message->toolCallId() === $callId) {
                return $message->content();
            }
        }

        return null;
    }

    private static function plain(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a/', '', $frame);
    }

    private static function readTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'reads';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('', str_repeat("a.php line of source\n", 200));
            }
        };
    }

    private function settle(PromiseInterface $promise): mixed
    {
        return $this->drainUntilSettled($promise);
    }

    private function drainUntilSettled(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the turn never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 2.2-2: the session's context ledger crosses the turn both ways. It
 * goes IN on the backend ({@see EngineBackend::withContextLedger()}), so the
 * turn's first request is projected through what earlier turns pruned; it
 * comes OUT on the reply ({@see Message::$contextLedger}) with the turn's refs
 * fixed — across the fork as the `result` frame's `contextLedger` key, read
 * back leniently. A delegated sub-agent never sees it.
 */
final class EngineBackendLedgerFrameTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testTheTurnStartsFromTheSessionLedgerAndHandsBackTheOneItEndsWith(): void
    {
        $provider = self::provider();

        $reply = $this->backend($provider)->withContextLedger(self::sessionLedger())->complete(self::history());

        $sent = [];
        foreach ($provider->requests[0]->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $sent[$message->toolCallId()] = $message->content();
            }
        }
        $this->assertSame(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), $sent['old'], 'an earlier turn\'s prune holds on the first request');

        $ledger = $reply->contextLedger;
        $this->assertInstanceOf(ContextLedger::class, $ledger);
        $this->assertTrue($ledger->isPruned('old'), 'what the session pruned comes back');
        // Roadmap 3.B-4: the prompts are numbered in the same order — r1
        // "read a.php", r3 "now echo".
        $this->assertSame(2, $ledger->refOf('old'), 'refs are fixed in the order the model read the rows');
        $this->assertSame(4, $ledger->refOf('new'), 'the turn\'s own result keeps its ref');
        $this->assertSame(5, $ledger->nextRef);
    }

    public function testAHostThatKeepsNoLedgerGetsNoneBack(): void
    {
        $reply = $this->backend(self::provider())->complete(self::history());

        $this->assertNull($reply->contextLedger, 'no ledger handed in, none handed back: -p and background runs are unchanged');
    }

    public function testTheForkedTurnHandsBackTheSameLedgerAsTheInProcessOne(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl and the frame never crosses a serialize boundary');
        }

        $sync = $this->backend(self::provider())->withContextLedger(self::sessionLedger())->complete(self::history());
        $forked = $this->drainUntilSettled(
            $this->backend(self::provider())->withContextLedger(self::sessionLedger())->completeAsync(self::history()),
        );

        $this->assertInstanceOf(Message::class, $forked);
        $this->assertNotNull($forked->contextLedger, 'the ledger crossed the result frame');
        $this->assertEquals($sync->contextLedger, $forked->contextLedger);
    }

    public function testAFrameWithoutTheKeyCarriesNoLedgerAndGarbageIsReadLeniently(): void
    {
        $this->assertNull($this->settleFrame(['ok' => true, 'content' => 'x'])->contextLedger, 'an older child, or a turn handed none');

        $read = $this->settleFrame(['ok' => true, 'content' => 'x', 'contextLedger' => [
            'prunes' => ['not an entry', self::entry('kept')->toArray()],
            'refs' => ['kept' => 4, 'dup' => 4],
        ]])->contextLedger;
        $this->assertInstanceOf(ContextLedger::class, $read);
        $this->assertSame(['kept'], array_keys($read->prunes));
        $this->assertSame(['kept' => 4], $read->refs);

        $this->assertNull($this->settleFrame(['ok' => true, 'content' => 'x', 'contextLedger' => 'garbage'])->contextLedger);
    }

    public function testADelegatedRunNeverInheritsTheSessionLedger(): void
    {
        $capture = new class () implements Tool, DelegatesToEngine {
            public ?EngineBackend $engine = null;

            public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): Tool
            {
                $this->engine = $engine;

                return $this;
            }

            public function name(): string
            {
                return 'Task';
            }

            public function description(): string
            {
                return 'delegates';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: '');
            }
        };
        $backend = $this->backend(self::provider())->withTools([$capture])->withContextLedger(self::sessionLedger());

        (new \ReflectionMethod($backend, 'turnTools'))->invoke($backend, null, null, null);

        $this->assertNotNull($capture->engine);
        $this->assertNull($capture->engine->contextLedger(), 'a sub-agent\'s conversation is its own');
        $this->assertNotNull($backend->contextLedger(), 'and the parent keeps its ledger');
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function backend(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-ledger-frame-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)->withTools([self::echoTool()]);
    }

    private static function provider(): ScriptedProvider
    {
        return new ScriptedProvider([
            new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('new', 'echo', ['q' => 1])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);
    }

    /**
     * An earlier turn that read a.php, replayed structurally (1.B-2), then
     * the new prompt.
     *
     * @return list<Message>
     */
    private static function history(): array
    {
        return [
            Message::user('read a.php'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'old')])->withStepId('s_x_1')->withUserVisible(false),
            Message::assistant(str_repeat('a', 400))->withToolResults([new ToolResult('Read', str_repeat('a', 400), null, 'old')])->withStepId('s_x_1'),
            Message::assistant('read it')->withStepId('s_x_2'),
            Message::user('now echo'),
        ];
    }

    private static function sessionLedger(): ContextLedger
    {
        return ContextLedger::new()->withPrune(self::entry('old'));
    }

    private static function entry(string $id): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 90);
    }

    /** @param array<string, mixed> $frame */
    private function settleFrame(array $frame): Message
    {
        $backend = $this->backend(self::provider());
        $deferred = new Deferred();

        (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame, $deferred, null);

        $resolved = null;
        $deferred->promise()->then(static function (Message $m) use (&$resolved): void {
            $resolved = $m;
        });
        $this->assertInstanceOf(Message::class, $resolved, 'the frame must settle, not reject');

        return $resolved;
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
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }

    private static function echoTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'echo';
            }

            public function description(): string
            {
                return 'answers';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: 'echoed');
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.B-3, in the engine: the model's `Prune` call takes effect INSIDE
 * the turn — the very next step's request already carries the placeholder —
 * because the tool is bound to the turn's own ledger
 * ({@see EngineBackend::turnTools()}), and the turn hands that ledger back on
 * its reply, across the fork too. The tool is offered only to a turn whose
 * host keeps the session's ledger in the `auto` mode.
 */
final class ModelPruneInTurnTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testThePruneLandsOnTheNextStepOfTheSameTurnAndComesBackOnTheReply(): void
    {
        $provider = self::provider();

        $reply = $this->engine($provider)->withContextLedger(self::autoLedger())->complete([Message::user('read a.php, then tidy up')]);

        $this->assertCount(3, $provider->requests);
        $this->assertSame(RefTag::appendTo(self::bigOutput(), 2), self::sent($provider->requests[1], 'c1'), 'the model read the output, tagged r2 (r1 is the prompt, roadmap 3.B-4)');
        $this->assertSame(
            RefTag::appendTo(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), 2),
            self::sent($provider->requests[2], 'c1'),
            'the step after the Prune call is already projected through it',
        );
        $this->assertStringStartsWith('Pruned 1 output', self::sent($provider->requests[2], 'p1'));

        $entry = $reply->contextLedger?->prune('c1');
        $this->assertNotNull($entry, 'the turn hands back the ledger the model changed');
        $this->assertSame(PruneAuthor::Model, $entry->by);
        $this->assertSame(PruneKind::Output, $entry->kind);
        $this->assertSame(2, $reply->contextLedger->refOf('c1'), 'and its ref is fixed as the model read it');
    }

    public function testTheToolIsOfferedOnlyToAnAutoSessionWhoseHostKeepsALedger(): void
    {
        $offered = static function (?ContextLedger $ledger): bool {
            $provider = new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000);
            $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withTools([self::readTool(), Prune::new()])->withContextLedger($ledger);
            $engine->complete([Message::user('hi')]);

            return \in_array('Prune', array_map(static fn (Tool $t): string => $t->name(), $provider->requests[0]->tools ?? []), true);
        };

        $this->assertTrue($offered(self::autoLedger()));
        $this->assertFalse($offered(ContextLedger::new()->withDefaultMode(PruningMode::Auto)->withMode(PruningMode::Manual)), 'manual: only the person prunes');
        $this->assertFalse($offered(ContextLedger::new()->withDefaultMode(PruningMode::Off)), 'off');
        $this->assertFalse($offered(null), 'no host ledger: -p, a background run, a sub-agent');
    }

    public function testTheForkedTurnHandsBackTheSamePrune(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl and the frame never crosses a serialize boundary');
        }

        $sync = $this->engine(self::provider())->withContextLedger(self::autoLedger())->complete([Message::user('go')]);
        $forked = $this->drainUntilSettled(
            $this->engine(self::provider())->withContextLedger(self::autoLedger())->completeAsync([Message::user('go')]),
        );

        $this->assertInstanceOf(Message::class, $forked);
        $this->assertTrue($forked->contextLedger?->isPruned('c1'), 'the model\'s prune crossed the result frame');
        // The configured default mode never crosses: the host re-reads it
        // every turn ({@see ContextLedger::$defaultMode}).
        $this->assertEquals($sync->contextLedger?->toArray(), $forked->contextLedger->toArray());
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-model-prune-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)->withTools([self::readTool(), Prune::new()]);
    }

    private static function autoLedger(): ContextLedger
    {
        return ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    private static function provider(): ScriptedProvider
    {
        return new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r2']], 'reason' => 'done'])]),
            new CompleteResponse(content: 'tidied'),
        ], contextWindow: 1_000_000);
    }

    private static function bigOutput(): string
    {
        return str_repeat("a.php line of source\n", 200);
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

    private static function readTool(): Tool
    {
        return new class (self::bigOutput()) implements Tool {
            public function __construct(private readonly string $output)
            {
            }

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
                return new ToolResult('', $this->output);
            }
        };
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
}

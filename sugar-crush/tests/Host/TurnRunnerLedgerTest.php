<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 2.2-2: {@see TurnRunner} keeps a session's context ledger between
 * turns. Each engine dispatch starts from the session's ledger — read from
 * the store when the session persists, synced against the rows the history
 * still has — and the ledger the turn ends with is saved back and taken off
 * the reply before the reply becomes a row.
 */
final class TurnRunnerLedgerTest extends TestCase
{
    private string $dir;

    private ?EnhancedSessionStore $store = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-runner-ledger-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
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

    public function testATurnStartsFromTheStoredLedgerAndTheOneItEndsWithIsSaved(): void
    {
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
        $transcripts = TranscriptStore::new($this->store);
        $this->store->saveContextLedger('s', ContextLedger::new()->withPrune(self::entry('old'))->withPrune(self::entry('rewound away')));

        $reply = $this->runTurn(TurnRunner::new(), $transcripts, 's');

        $this->assertNull($reply->contextLedger, 'the ledger is transport: it never reaches the row');
        $saved = $this->store->loadContextLedger('s');
        $this->assertNotNull($saved);
        $this->assertTrue($saved->isPruned('old'), 'what the session pruned is still pruned');
        $this->assertFalse($saved->isPruned('rewound away'), 'a prune naming a row the history lost was forgotten before the turn');
        $this->assertSame(['old' => 2, 'new' => 4], self::toolRefs($saved), 'the turn\'s refs are the session\'s now (r1, r3: the prompts, roadmap 3.B-4)');
    }

    public function testWithoutAStoreTheRunnerKeepsEachSessionsLedgerItself(): void
    {
        $runner = TurnRunner::new();
        $this->assertTrue($runner->ledger(null, null)->isEmpty());

        $this->runTurn($runner, null, null);
        $first = $runner->ledger(null, null);
        $this->assertSame(['old' => 2, 'new' => 4], self::toolRefs($first));

        $runner->saveLedger(null, 'other', ContextLedger::new()->withPrune(self::entry('x')));
        $this->assertTrue($runner->ledger(null, 'other')->isPruned('x'));
        $this->assertSame($first, $runner->ledger(null, null), 'one ledger per session');
    }

    public function testABackendThatRunsNoEngineTurnIsLeftAlone(): void
    {
        $runner = TurnRunner::new();
        $thunk = $runner->start(new EchoBackend(), self::history(), new \ArrayObject(), 1, new CancellationToken(), false);

        $this->settle($thunk());

        $this->assertTrue($runner->ledger(null, null)->isEmpty(), 'nothing came back, nothing is kept');
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function runTurn(TurnRunner $runner, ?TranscriptStore $transcripts, ?string $sessionId): Message
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('new', 'echo', [])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir)->withTools([self::echoTool()]);

        $thunk = $runner->start($backend, self::history(), new \ArrayObject(), 1, new CancellationToken(), false, transcripts: $transcripts, sessionId: $sessionId);
        $msg = $this->settle($thunk());

        $this->assertTrue($msg instanceof AssistantMsg || $msg instanceof BackendToolEventsMsg);

        return $msg->message;
    }

    /** @return list<Message> */
    private static function history(): array
    {
        return [
            Message::user('read a.php'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'old')])->withStepId('s_x_1')->withUserVisible(false),
            Message::assistant('a')->withToolResults([new ToolResult('Read', 'a', null, 'old')])->withStepId('s_x_1'),
            Message::assistant('read it')->withStepId('s_x_2'),
            Message::user('now echo'),
        ];
    }

    private static function entry(string $id): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 50);
    }

    private function settle(PromiseInterface $promise): mixed
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

    /**
     * The refs of the tool results alone — the prompts carry refs too
     * (roadmap 3.B-4), keyed by their bytes.
     *
     * @return array<string, int>
     */
    private static function toolRefs(ContextLedger $ledger): array
    {
        return array_filter($ledger->refs, static fn (int|string $key): bool => !ContextLedger::isUserRowKey((string) $key), ARRAY_FILTER_USE_KEY);
    }
}

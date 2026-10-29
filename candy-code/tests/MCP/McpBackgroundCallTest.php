<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\McpBackgroundInbox;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpBackgroundCalls;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * C-MCP-3.d — a long MCP call hands the TURN over at the threshold while the
 * exchange keeps running in a forked child, and its answer arrives later
 * through the turn inbox as a message.
 *
 * THE HAZARD THIS SUITE MANAGES: every armed call here forks from inside the
 * PHPUnit process. The child leaves through {@see ForkedChild::exitNow()} —
 * SIGKILL on itself, no shutdown sequence, no republished output buffer, no
 * second runner — exactly as the census in
 * {@see \SugarCraft\Code\Tests\Support\ForkedChildExitConventionTest} demands,
 * and the parent REAPS it (bounded poll, then a defensive kill) in tearDown
 * before the sandboxed home is removed, so no test can leak a sleeper child
 * onto the machine or into another test's registry.
 *
 * EVERY THRESHOLD IS INJECTED (0.15 s) and every "slow" server sleeps 0.35 s;
 * the product figure of 120 s is pinned against the docs once, as a figure,
 * and never waited out here. Timeouts are polling BUDGETS, never assertion
 * bases — an exchange that finishes late is still the same exchange.
 *
 * The fake servers are plain {@see McpServer} implementations (not PHPUnit
 * mocks) because the call must run INSIDE a forked copy of this process,
 * where a mock's invocation bookkeeping would be meaningless — the sleep and
 * the reply are the whole contract.
 */
final class McpBackgroundCallTest extends TestCase
{
    use HomeSandboxTrait;

    private const THRESHOLD = 0.15;
    private const BACKGROUND_CALL_SLEEP_SECONDS = 0.35;

    private string $sandboxHome = '';

    /** @var list<int> */
    private array $expectedPids = [];

    protected function setUp(): void
    {
        // Lane-cf law: the trait snapshots on its FIRST call, so no env
        // clearing may precede it.
        $this->sandboxHome = $this->useHomeSandbox(sys_get_temp_dir() . '/cc-mcp-bg-' . getmypid() . '-' . uniqid('t', true));
        McpBackgroundCalls::resetPendingChildrenForTesting();
        $this->expectedPids = [];
    }

    protected function tearDown(): void
    {
        // Reap every child this test forked, bounded: poll to exit, then a
        // defensive SIGKILL, then the blocking reap that proves it is gone.
        // Nothing below may leave an orphan that outlives the temp home.
        foreach (McpBackgroundCalls::pendingChildren() as $pid) {
            $this->reap($pid);
        }
        foreach ($this->expectedPids as $pid) {
            $this->reap($pid);
        }
        McpBackgroundCalls::resetPendingChildrenForTesting();

        $registry = $this->sandboxHome . '/.candy-code/' . McpBackgroundCalls::RELATIVE_DIR;
        if (is_dir($registry)) {
            foreach (scandir($registry) ?: [] as $leftover) {
                if ($leftover !== '.' && $leftover !== '..') {
                    @unlink($registry . '/' . $leftover);
                }
            }
            @rmdir($registry);
        }
        @rmdir($this->sandboxHome . '/.candy-code');
        @rmdir($this->sandboxHome);

        $this->restoreHomeSandbox();
    }

    // =========================================================================
    // The hand-off
    // =========================================================================

    public function testASlowBridgedCallHandsOffAtTheThresholdWithAJobIdPlaceholder(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');

        $result = $bridge->executeWithHeartbeat(['id' => 'call_bg1'], static function (): void {
        });

        self::assertFalse($result->isError(), 'a handed-off call is not an error, it is a promise kept');
        self::assertStringContainsString('is still running as background MCP job', $result->content());
        self::assertStringContainsString('the result will arrive as a message', $result->content());
        self::assertStringNotContainsString('slow answer', $result->content(), 'the placeholder never pre-delivers the result');

        // The job id the placeholder quotes is the registry name the child
        // will publish under, and it carries the server/tool slug prefix.
        self::assertSame(1, preg_match('/background MCP job `(srv-ping-\d{13}-[0-9a-f]{8})`/', $result->content(), $m), $result->content());
        $pending = McpBackgroundCalls::pendingChildren();
        self::assertArrayHasKey($m[1], $pending, 'the handed-off child is this process\'s until it is reaped');
        $this->expectedPids[] = $pending[$m[1]];

        // The child finishes on its own schedule; poll to publication, then
        // drain so the same job cannot leak into a later test.
        self::assertTrue($this->waitUntilPublished(), 'the child publishes its finished exchange');
        self::assertCount(1, McpBackgroundCalls::takeFinished());
    }

    public function testTheFinishedResultArrivesThroughTheInboxAsAMessage(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');
        $bridge->executeWithHeartbeat(['id' => 'call_bg2'], static function (): void {
        });
        self::assertTrue($this->waitUntilPublished());

        $inbox = McpBackgroundInbox::forTurn();
        self::assertNotNull($inbox, 'the sandboxed home can host the registry');
        self::assertTrue($inbox->pending());

        $rows = $inbox->drain(1);
        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]->role(), 'the answer arrives as a message to the model, like steer rows');
        $text = $rows[0]->content();
        self::assertStringContainsString('has finished:', $text);
        self::assertStringContainsString('slow answer', $text, 'the child\'s rendered row arrives verbatim');
        self::assertFalse($inbox->pending(), 'one claim, one delivery');
        self::assertSame([], $inbox->drain(1));
    }

    public function testTheDispatchedSpellingHandsOffTheSameWay(): void
    {
        $descriptor = new McpTool(name: 'ping', description: str_repeat('a', 3000), inputSchema: [], serverName: 'srv');
        $client = $this->clientHolding($this->slowServer('slow answer', $descriptor), $descriptor);
        $shape = McpToolShape::derive($client, [$descriptor], [], 10);
        self::assertTrue($shape->lazy, 'fixture: the pair must be dispatched, not first-class');

        $tool = (new McpCallTool($shape))->withBackgroundAfter(self::THRESHOLD);
        $result = $tool->executeWithHeartbeat(
            ['id' => 'call_bg3', 'server' => 'srv', 'tool' => 'ping', 'arguments' => []],
            static function (): void {
            },
        );

        self::assertFalse($result->isError());
        self::assertStringContainsString('is still running as background MCP job', $result->content());
        self::assertStringContainsString(McpToolBridge::wireNameFor('srv', 'ping'), $result->content(), 'the dispatched placeholder names the wire tool');
        self::assertTrue($this->waitUntilPublished());
        self::assertCount(1, McpBackgroundCalls::takeFinished());
    }

    // =========================================================================
    // The promises the feature makes around the hand-off
    // =========================================================================

    public function testAFastArmedCallReturnsThePlainPathBytes(): void
    {
        $descriptor = $this->descriptor();

        $plain = $this->bridgeWith($this->slowServer('quick answer', $descriptor, sleep: 0.0), $descriptor)
            ->execute(['id' => 'call_fast']);

        $armed = $this->bridgeWith($this->slowServer('quick answer', $descriptor, sleep: 0.0), $descriptor)
            ->withBackgroundAfter(self::THRESHOLD)
            ->executeWithHeartbeat(['id' => 'call_fast'], static function (): void {
            });

        self::assertSame($plain->content(), $armed->content(), 'below the threshold the forked render is byte-for-byte the inline one');
        self::assertSame($plain->isError(), $armed->isError());
        self::assertSame('call_fast', $armed->toolCallId());
        self::assertSame([], McpBackgroundCalls::pendingChildren(), 'a collected child is reaped inside await()');
        self::assertFalse(McpBackgroundCalls::hasFinished(), 'the parent claimed its own child\'s row');
    }

    public function testAHeadlessLatchWaitsForTheRealResultAndNeverForks(): void
    {
        $bridge = $this->bridgingSlowCall('plain answer')->withoutInteractiveUser('test headless');

        $result = $bridge->executeWithHeartbeat(['id' => 'call_p'], static function (): void {
        });

        self::assertFalse($result->isError());
        self::assertStringContainsString('plain answer', $result->content(), 'the `-p` caller gets the exchange it waited for, as ever');
        self::assertStringNotContainsString('background MCP job', $result->content());
        self::assertSame([], McpBackgroundCalls::pendingChildren(), 'a latched run never forks a background child');
        self::assertDirectoryDoesNotExist($this->registryDir());
    }

    public function testTheChildIsReapedNotMerelyForgotten(): void
    {
        $bridge = $this->bridgingSlowCall('slow answer');
        $bridge->executeWithHeartbeat(['id' => 'call_reap'], static function (): void {
        });

        $pending = McpBackgroundCalls::pendingChildren();
        self::assertCount(1, $pending, 'the hand-off leaves exactly one live child');
        $pid = reset($pending);

        self::assertTrue($this->waitUntilPublished());
        // Poll-then-reap, the census idiom: the registry scan takes the row
        // AND reaps the child — but publication can outrun exit by a few
        // scheduler ticks, so the reap is polled to settled, never assumed.
        $frames = McpBackgroundCalls::takeFinished();
        $deadline = hrtime(true) + 2_000_000_000;
        while (McpBackgroundCalls::pendingChildren() !== [] && hrtime(true) < $deadline) {
            $frames = array_merge($frames, McpBackgroundCalls::takeFinished());
            usleep(10_000);
        }
        self::assertCount(1, $frames);
        self::assertSame([], McpBackgroundCalls::pendingChildren());

        $status = 0;
        self::assertSame(-1, @pcntl_waitpid($pid, $status, WNOHANG), 'ECHILD: the pid is reaped, not just dropped from the roster');
    }

    public function testAChildThatDiesWithoutPublishingReportsLostAndNeverSilence(): void
    {
        $outcome = McpBackgroundCalls::await(
            static function (): never {
                throw new \RuntimeException('the exchange blew up');
            },
            5.0,
            null,
            'srv',
            'ping',
            'call_lost',
        );

        self::assertSame('lost', $outcome['kind'], 'a child that exited with no payload is a loud error row, never a promise nobody keeps');
        self::assertSame([], McpBackgroundCalls::pendingChildren());
    }

    // =========================================================================
    // The fixed figure, pinned across code / lang / docs in one breath
    // =========================================================================

    public function testTheHandOffFigureAndSentenceAgreeAcrossCodeLangAndDocs(): void
    {
        self::assertSame(120.0, McpBackgroundCalls::DEFAULT_AFTER_SECONDS, 'the CC-parity figure is the product threshold');

        $running = Lang::t('mcp.background.running', ['tool' => 'T', 'server' => 'S', 'id' => 'J']);
        self::assertStringContainsString('still running as background MCP job `J`', $running);
        self::assertStringContainsString('the result will arrive as a message', $running);

        $docs = (string) file_get_contents(__DIR__ . '/../../docs/MCP.md');
        self::assertStringContainsString('still running as', $docs);
        self::assertStringContainsString('background MCP job', $docs);
        self::assertStringContainsString('the result will arrive as a message', $docs);
        self::assertStringContainsString('120 seconds', $docs, 'the docs quote the same figure the code arms by default');

        $en = require __DIR__ . '/../../lang/en.php';
        $keys = array_filter(array_keys($en), static fn (string $key): bool => str_starts_with($key, 'mcp.background.'));
        self::assertSame(
            ['mcp.background.running', 'mcp.background.lost', 'mcp.background.finished'],
            array_values($keys),
            'the three rows, in the order en.php ships them',
        );
    }

    // =========================================================================
    // Harness
    // =========================================================================

    private function descriptor(): McpTool
    {
        return new McpTool(name: 'ping', description: 'drive me', inputSchema: [], serverName: 'srv');
    }

    /** @param McpServer $server */
    private function clientHolding(McpServer $server, McpTool $descriptor): McpClient
    {
        $client = new McpClient('/nonexistent/mcp-background/' . uniqid('c', true) . '.json', unrestricted: true);
        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, [$descriptor->serverName => $server]);

        return $client;
    }

    /**
     * A fake whose callTool sleeps $sleep seconds and then answers $text.
     * The sleep is the WHOLE simulation: the child forked by await() carries
     * this closure's process, wakes, publishes, and leaves through exitNow.
     */
    private function slowServer(string $text, McpTool $descriptor, ?float $sleep = null): McpServer
    {
        $sleep ??= self::BACKGROUND_CALL_SLEEP_SECONDS;

        return new class ($descriptor, $text, $sleep) implements McpServer {
            public function __construct(
                private readonly McpTool $tool,
                private readonly string $text,
                private readonly float $sleep,
            ) {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                if ($this->sleep > 0.0) {
                    usleep((int) round($this->sleep * 1_000_000));
                }

                return ['content' => [['type' => 'text', 'text' => $this->text]]];
            }
        };
    }

    private function bridgeWith(McpServer $server, ?McpTool $descriptor = null): McpToolBridge
    {
        $descriptor ??= $this->descriptor();

        return new McpToolBridge($this->clientHolding($server, $descriptor), $descriptor);
    }

    private function bridgingSlowCall(string $text): McpToolBridge
    {
        return $this->bridgeWith($this->slowServer($text, $this->descriptor()))->withBackgroundAfter(self::THRESHOLD);
    }

    private function registryDir(): string
    {
        $home = HomeDirectory::owned();
        self::assertNotNull($home, 'fixture: the sandbox must expose an owned home');

        return rtrim($home, '/') . '/.candy-code/' . McpBackgroundCalls::RELATIVE_DIR;
    }

    /** Bounded poll until the child publishes; budgets, never assertion bases. */
    private function waitUntilPublished(float $budgetSeconds = 5.0): bool
    {
        $deadline = hrtime(true) + (int) round($budgetSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (McpBackgroundCalls::hasFinished()) {
                return true;
            }
            usleep(10_000);
        }

        return McpBackgroundCalls::hasFinished();
    }

    private function reap(int $pid): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        while (hrtime(true) < $deadline) {
            $status = 0;
            $gone = @pcntl_waitpid($pid, $status, WNOHANG);
            if ($gone === $pid) {
                return;
            }
            if ($gone === -1) {
                return; // already reaped (ECHILD)
            }
            usleep(10_000);
        }

        if (\function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        }
        $status = 0;
        @pcntl_waitpid($pid, $status);
    }
}

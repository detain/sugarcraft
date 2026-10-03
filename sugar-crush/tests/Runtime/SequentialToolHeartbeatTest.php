<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\McpToolBridge;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Item 0.4-b: a call executed ALONE beats while it works.
 *
 * Before this, only a parallel group fed the turn's liveness sink while it
 * waited ({@see Runtime::executeConcurrently()}); a lone `Bash`, `Grep` or
 * `mcp__*` call was silent for its whole run, and the forked turn's 120 s
 * idle ceiling ({@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()})
 * killed the WHOLE turn under any sequential tool that ran longer than that —
 * whatever the tool's own `timeout` said. The sink's other end (beat → frame
 * → idle-deadline reset) is pinned by EngineBackendHeartbeatThreadingTest;
 * these rows pin the half that was missing: the beat reaching the tool's wait
 * loop, throttled, and never from a foreign process.
 *
 * @see AcceptsHeartbeat
 */
final class SequentialToolHeartbeatTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private ?string $workDir = null;

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        if ($this->workDir !== null) {
            foreach (glob($this->workDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->workDir);
        }
    }

    public function testALoneOptedInToolBeatsAboutOnceASecondWhileItWorks(): void
    {
        $tool = self::waitingTool(2.3);
        $beats = [];

        $messages = $this->dispatch([new ToolCall('call_1', 'waiting', [])], [$tool], static function () use (&$beats): void {
            $beats[] = microtime(true);
        });

        self::assertSame('waited via executeWithHeartbeat', $messages[0]->content());
        self::assertGreaterThanOrEqual(2, count($beats), 'a 2.3 s wait must beat at least twice');
        self::assertLessThanOrEqual(4, count($beats), 'the tool fires every 20 ms; the Runtime must throttle to ~1/s');
        for ($i = 1, $n = count($beats); $i < $n; $i++) {
            self::assertGreaterThanOrEqual(0.95, $beats[$i] - $beats[$i - 1], 'beats closer than the 1 s throttle');
        }
    }

    public function testAToolThatDoesNotOptInRunsThroughExecuteAndNeverBeats(): void
    {
        $beats = 0;

        $messages = $this->dispatch([new ToolCall('call_1', 'plain', [])], [self::plainTool()], static function () use (&$beats): void {
            $beats++;
        });

        self::assertSame('plain ran', $messages[0]->content());
        self::assertSame(0, $beats);
    }

    public function testWithNoSinkAnOptedInToolTakesTheOrdinaryExecutePath(): void
    {
        $messages = $this->dispatch([new ToolCall('call_1', 'waiting', [])], [self::waitingTool(0.05)], null);

        self::assertSame('waited via execute', $messages[0]->content());
    }

    /**
     * The beat writes a frame on the turn's socket, so it must never fire from
     * a process the tool forked — two writers on one socket interleave frames.
     */
    public function testTheBeatIsInertInAProcessTheToolForked(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::assertFalse(\function_exists('pcntl_fork'));

            return;
        }

        $this->workDir = sys_get_temp_dir() . '/sc_hb_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        $log = $this->workDir . '/beats';

        $this->dispatch([new ToolCall('call_1', 'forking', [])], [self::forkingTool(fn (): int => $this->forkTracked())], static function () use ($log): void {
            file_put_contents($log, getmypid() . "\n", FILE_APPEND);
        });

        $writers = array_values(array_unique(array_filter(explode("\n", (string) @file_get_contents($log)))));
        self::assertSame([(string) getmypid()], $writers, 'only the dispatching process may beat');
    }

    /** Bash end to end: the beat reaches runCaptured()'s select loop. */
    public function testALoneBashCommandBeatsWhileItRuns(): void
    {
        $beats = 0;

        $messages = $this->dispatch(
            [new ToolCall('call_1', 'Bash', ['command' => 'sleep 2.2; echo done', 'description' => 'Sleep then print'])],
            [new Bash()],
            static function () use (&$beats): void {
                $beats++;
            },
        );

        self::assertSame('done', $messages[0]->content());
        self::assertGreaterThanOrEqual(2, $beats);
    }

    /**
     * An `mcp__*` bridge end to end: the beat crosses McpClient and the
     * stdio adapter into the sugar-mcp transport's wait loop while a slow
     * server works — the unbounded call (E646) kept visibly alive.
     */
    public function testALoneMcpCallBeatsWhileTheServerWorks(): void
    {
        $this->workDir = sys_get_temp_dir() . '/sc_hb_mcp_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        $script = $this->workDir . '/slow.php';
        file_put_contents($script, <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg['id']];
                if ($msg['method'] === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'slow', 'version' => '0']];
                } elseif ($msg['method'] === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'nap', 'description' => 'naps',
                        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]]];
                } elseif ($msg['method'] === 'tools/call') {
                    usleep(2_300_000);
                    $out['result'] = ['content' => [['type' => 'text', 'text' => 'rested']]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP);
        $config = $this->workDir . '/.mcp.json';
        file_put_contents($config, (string) json_encode(['mcpServers' => ['slow' => [
            'command' => PHP_BINARY,
            'args' => [$script],
        ]]]));

        $client = new McpClient($config, unrestricted: true);
        $client->startServers();

        try {
            $tools = $client->listTools();
            self::assertCount(1, $tools, 'fixture: the slow server must come up with its one tool');
            $bridge = new McpToolBridge($client, $tools[0]);

            $beats = 0;
            $messages = $this->dispatch([new ToolCall('call_1', $bridge->name(), [])], [$bridge], static function () use (&$beats): void {
                $beats++;
            });
        } finally {
            $client->stopServers();
        }

        self::assertSame('rested', $messages[0]->content());
        self::assertGreaterThanOrEqual(2, $beats);
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool> $tools
     * @return list<ToolResultMessage>
     */
    private function dispatch(array $calls, array $tools, ?\Closure $heartbeat): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('heartbeat-stub');
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($runtime, $calls, $app, null, null, $heartbeat), false));
    }

    /**
     * Opted in: waits $seconds, firing the beat every 20 ms — far faster than
     * any sink wants, so the Runtime's throttle is what the count measures.
     */
    private static function waitingTool(float $seconds): Tool
    {
        return new class($seconds) implements Tool, AcceptsHeartbeat {
            public function __construct(private float $seconds) {}

            public function name(): string
            {
                return 'waiting';
            }

            public function description(): string
            {
                return 'waits';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                usleep((int) ($this->seconds * 1_000_000));

                return new ToolResult(toolCallId: '', content: 'waited via execute');
            }

            public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
            {
                $until = microtime(true) + $this->seconds;
                while (microtime(true) < $until) {
                    $heartbeat();
                    usleep(20_000);
                }

                return new ToolResult(toolCallId: '', content: 'waited via executeWithHeartbeat');
            }
        };
    }

    private static function plainTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'plain';
            }

            public function description(): string
            {
                return 'does not opt in';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                usleep(300_000);

                return new ToolResult(toolCallId: '', content: 'plain ran');
            }
        };
    }

    /**
     * Beats once in the dispatching process, waits out the throttle, then
     * forks a child that beats too — which must be a no-op there. The fork
     * goes through the test's tracked fork so tearDown() can reap it.
     *
     * @param \Closure(): int $fork
     */
    private static function forkingTool(\Closure $fork): Tool
    {
        return new class ($fork) implements Tool, AcceptsHeartbeat {
            public function __construct(private readonly \Closure $fork)
            {
            }

            public function name(): string
            {
                return 'forking';
            }

            public function description(): string
            {
                return 'forks a beating child';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'no beat');
            }

            public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
            {
                $heartbeat();
                usleep(1_100_000);
                $pid = ($this->fork)();
                if ($pid === 0) {
                    $heartbeat();
                    \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
                }
                if ($pid > 0) {
                    pcntl_waitpid($pid, $status);
                }

                return new ToolResult(toolCallId: '', content: 'forked');
            }
        };
    }
}

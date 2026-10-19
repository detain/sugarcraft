<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * MCP-2 (plan_comparison §3): `prompts/list` rows become launch-frozen
 * `/mcp__<server>__<prompt>` placeholder commands, and `prompts/get` runs at
 * submission — never at launch, never on a keystroke path.
 *
 * The fixtures plant real McpClient instances with anonymous provider servers
 * (the McpClientStartedSnapshotTest idiom) because the behaviors under test —
 * gate parity, per-server error isolation, the one prime — live in the client,
 * and a double of the client would re-test the double.
 */
final class McpPromptCommandsTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = (string) realpath(sys_get_temp_dir()) . '/sc_mcpprompts_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tempDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->tempDir . '/' . $entry);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testARosterRowBecomesAPlaceholderUnderTheOneToolSanitizer(): void
    {
        $server = new class implements McpServer, McpPromptProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [
                    [
                        'name' => 'deploy/app',
                        'description' => "two\nlines\x07of junk",
                        'arguments' => [
                            ['name' => 'target', 'required' => true],
                            ['name' => 'style'],
                            ['junk' => true],
                        ],
                    ],
                ];
            }

            /** @return array<mixed> */
            public function getPrompt(string $name, array $arguments = []): array
            {
                return ['messages' => []];
            }
        };

        $specs = McpPromptCommands::specs($this->providerFor(['weird srv' => $server]));

        $name = McpToolBridge::NAME_PREFIX . McpToolBridge::sanitize('weird srv') . '__' . McpToolBridge::sanitize('deploy/app');
        self::assertSame([$name], array_keys($specs));
        $spec = $specs[$name];
        self::assertSame('mcp', $spec->tier);
        self::assertTrue($spec->isFileBased(), 'a placeholder must reach the file-shaped dispatch chain');
        self::assertTrue(McpPromptCommands::isMcpPrompt($spec));
        self::assertSame('two lines of junk', $spec->description, 'popup rows are one flat line');
        self::assertSame('<target> [style]', $spec->argumentHint);
        self::assertSame('MCP', $spec->category);
    }

    public function testCollidingRowsKeepTheFirstAndJunkRowsAreSkipped(): void
    {
        $server = new class implements McpServer, McpPromptProvider {
            public function start(): void {}
            public function stop(): void {}

            public function listTools(): array
            {
                return [];
            }

            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            public function listPrompts(): array
            {
                return [
                    ['name' => 'x', 'description' => 'first'],
                    ['name' => 'x', 'description' => 'second'],
                    ['name' => ''],
                    ['description' => 'nameless'],
                    'not even an array',
                    ['name' => 'kept'],
                ];
            }

            public function getPrompt(string $name, array $arguments = []): array
            {
                return ['messages' => []];
            }
        };

        $specs = McpPromptCommands::specs($this->providerFor(['srv' => $server]));

        self::assertSame(['mcp__srv__x', 'mcp__srv__kept'], array_keys($specs));
        self::assertSame('first', $specs['mcp__srv__x']->description);
        self::assertStringContainsString('MCP prompt "kept"', $specs['mcp__srv__kept']->description);
    }

    public function testNoClientNoServerAndRefusalAllCostTheRosterNotTheLaunch(): void
    {
        self::assertSame([], McpPromptCommands::specs(null));
        self::assertSame([], McpPromptCommands::specs(static fn (): ?McpClient => null));

        // A server whose listPrompts throws: prime isolates it, specs is empty.
        $thrower = $this->promptServer(throws: true);
        self::assertSame([], McpPromptCommands::specs($this->providerFor(['bad' => $thrower])));
    }

    public function testTheSurfacePassPrimesOnceKeepsGateParityAndServesThePanelCount(): void
    {
        $good = $this->promptServer(rows: [['name' => 'ok', 'description' => 'fine']]);
        $bad = $this->promptServer(throws: true);
        $blocked = $this->promptServer(rows: [['name' => 'nope', 'description' => 'never surfaces']]);

        // The deny pattern spells its wildcard through chr(42): a bare
        // wildcard literal here would be a glob-shaped T_CONSTANT_ENCAPSED
        // token, and the GlobDialect corpus harvests every such token in
        // the tree — one new value would drift the census pair the
        // PathGlob class doc-block pins (the doc-block is permanent
        // re-pin territory; the fixture is not).
        $client = $this->seedServers(new McpClient($this->tempDir . '/unused.json', unrestricted: true, denyPatterns: ['blocked-' . chr(42) => 'deny']), [
            'good' => $good,
            'bad' => $bad,
            'blocked-srv' => $blocked,
        ]);

        $rows = $client->promptsRoster();
        self::assertSame(['good'], array_keys($rows), 'a throwing server and a denied server cost only themselves');
        self::assertSame(1, $good->asks, 'the first read primed');
        $client->promptsRoster();
        self::assertSame(1, $good->asks, 'the prime is once per client, not once per reader');

        // The panel count: a resource provider that answered carries its size;
        // prompt-only and refused servers carry no key at all.
        $snapshot = $client->startedSnapshot();
        self::assertArrayNotHasKey('resources', $snapshot['good'], 'a prompt-only server never claimed resources');
        self::assertArrayNotHasKey('resources', $snapshot['bad']);
        self::assertArrayNotHasKey('resources', $snapshot['blocked-srv'], 'denied means never asked, and never asked is not zero');

        $withResources = $this->promptServer(rows: [], resources: [['uri' => 'file://a'], ['uri' => 'file://b']]);
        $second = $this->seedServers(new McpClient($this->tempDir . '/other.json', unrestricted: true), ['both' => $withResources]);
        self::assertArrayNotHasKey('resources', $second->startedSnapshot()['both'] ?? [], 'the readout never primes — zero traffic is its contract');
        $second->primeSurfaceRosters();
        self::assertSame(2, $second->startedSnapshot()['both']['resources'] ?? null, 'the launch pass fills the panel count');
        self::assertSame(1, $withResources->asks);
    }

    public function testSubmissionFetchesTheBodyMapsArgumentsPositionallyAndKeepsTheTier(): void
    {
        $captured = null;
        $server = new class($captured) implements McpServer, McpPromptProvider {
            /** @param array<string, string>|null $sink */
            public function __construct(private mixed &$sink) {}

            public function start(): void {}
            public function stop(): void {}

            public function listTools(): array
            {
                return [];
            }

            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            public function listPrompts(): array
            {
                return [[
                    'name' => 'wrap',
                    'description' => 'wrap it',
                    'arguments' => [['name' => 'target'], ['name' => 'style']],
                ]];
            }

            public function getPrompt(string $name, array $arguments = []): array
            {
                $this->sink = $arguments;

                return ['messages' => [
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'LINE ONE']],
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'LINE TWO']],
                ]];
            }
        };
        $specs = McpPromptCommands::specs($this->providerFor(['srv' => $server]));
        $placeholder = $specs['mcp__srv__wrap'];

        $materialized = McpPromptCommands::materialize($placeholder, '"two words" blue', $this->providerFor(['srv' => $server]));

        self::assertIsArray($captured);
        self::assertSame(['target' => 'two words', 'style' => 'blue'], $captured);
        self::assertIsString($materialized?->template);
        self::assertSame("LINE ONE\n\nLINE TWO", $materialized?->template);
        self::assertSame('mcp', $materialized?->tier, 'the fetched body keeps the provenance the shell gate reads');
        self::assertSame($placeholder->name, $materialized?->name);
        self::assertInstanceOf(CommandSpec::class, $materialized);
        self::assertFalse(McpPromptCommands::isMcpPrompt($materialized), 'the fetched body is no longer a marker — it expands through the template machinery');
    }

    public function testEveryMaterializeRefusalIsTheWholePromptRefused(): void
    {
        $specsFor = fn (array $messages): ?CommandSpec => McpPromptCommands::specs(
            $this->providerFor(['srv' => $this->fetchingServer($messages)]),
        )['mcp__srv__p'] ?? null;
        $placeholder = $specsFor([['role' => 'user', 'content' => ['type' => 'text', 'text' => 'x']]]);
        self::assertNotNull($placeholder);

        // No client at all this late (config trusted no MCP after launch).
        self::assertNull(McpPromptCommands::materialize($placeholder ?? new CommandSpec('n', 'd', 'c'), '', null));
        self::assertNull(McpPromptCommands::materialize($placeholder ?? new CommandSpec('n', 'd', 'c'), '', static fn (): ?McpClient => null));

        // A server that throws.
        $throwing = $this->promptServer(rows: [['name' => 'p']], throwsGet: true);
        $marker = McpPromptCommands::specs($this->providerFor(['srv' => $throwing]))['mcp__srv__p'] ?? null;
        self::assertNotNull($marker);
        self::assertNull(McpPromptCommands::materialize($marker, '', $this->providerFor(['srv' => $throwing])));

        // A non-text part means the prompt meant the pair; forwarding prose
        // alone would send text that refers at content the model never got.
        $mixed = $specsFor([
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'look at the image']],
            ['role' => 'user', 'content' => ['type' => 'image', 'data' => 'AAAA']],
        ]);
        self::assertNull(McpPromptCommands::materialize($mixed ?? new CommandSpec('n', 'd', 'c'), '', $this->providerFor(['srv' => $this->fetchingServer([
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'look at the image']],
            ['role' => 'user', 'content' => ['type' => 'image', 'data' => 'AAAA']],
        ])])));

        // An all-empty body.
        $emptyServer = $this->fetchingServer([['role' => 'user', 'content' => ['type' => 'text', 'text' => "  \n"]]]);
        $emptyMarker = McpPromptCommands::specs($this->providerFor(['srv' => $emptyServer]))['mcp__srv__p'] ?? null;
        self::assertNotNull($emptyMarker);
        self::assertNull(McpPromptCommands::materialize($emptyMarker, '', $this->providerFor(['srv' => $emptyServer])));
    }

    public function testOnlyTheMarkerShapeMarksAPlaceholderAndFileRowsNeverMatch(): void
    {
        $plain = new CommandSpec('n', 'd', 'c', template: 'body with !`ls`', tier: 'mcp');
        self::assertFalse(McpPromptCommands::isMcpPrompt($plain), 'a fetched body or a forged template is not a placeholder');

        $wrongTier = new CommandSpec('n', 'd', 'c', template: (string) json_encode(['mcp-prompt' => 1, 'server' => 's', 'prompt' => 'p', 'arguments' => []]), tier: 'user');
        self::assertFalse(McpPromptCommands::isMcpPrompt($wrongTier), 'only the mcp tier is a placeholder');
    }

    public function testDiskRowsMergeOverPromptRowsInTheOneSeam(): void
    {
        $method = new \ReflectionMethod(Chat::class, 'mergeCustomCommandRows');
        $method->setAccessible(true);

        $row = static fn (string $name, ?string $tier): CommandSpec => new CommandSpec($name, 'd', 'c', template: 't', tier: $tier);
        $merged = $method->invoke(null, [
            'dup' => $row('dup', 'mcp'),
            'only-mcp' => $row('only-mcp', 'mcp'),
        ], [
            'dup' => $row('dup', 'project'),
            'only-disk' => $row('only-disk', 'user'),
        ]);

        self::assertSame(['dup', 'only-mcp', 'only-disk'], array_keys($merged));
        self::assertSame('project', $merged['dup']->tier, 'a command file always wins its name against a server');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** @return \Closure(): ?McpClient */
    private function providerFor(array $servers): \Closure
    {
        $client = $this->seedServers(new McpClient($this->tempDir . '/p' . bin2hex(random_bytes(4)) . '.json', unrestricted: true), $servers);

        return static fn (): McpClient => $client;
    }

    /** @param array<string, McpServer> $servers */
    private function seedServers(McpClient $client, array $servers): McpClient
    {
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, $servers);

        return $client;
    }

    private function promptServer(array $rows = [], bool $throws = false, array $resources = [], bool $throwsGet = false): McpServer
    {
        return new class($rows, $throws, $resources, $throwsGet) implements McpServer, McpPromptProvider, McpResourceProvider {
            public int $asks = 0;

            /** @param list<array<mixed>> $rows @param list<array<mixed>> $resources */
            public function __construct(private array $rows, private bool $throws, private array $resources, private bool $throwsGet) {}

            public function start(): void {}
            public function stop(): void {}

            public function listTools(): array
            {
                return [];
            }

            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            public function listPrompts(): array
            {
                $this->asks++;
                if ($this->throws) {
                    throw new \RuntimeException('transport is wedged');
                }

                return $this->rows;
            }

            public function getPrompt(string $name, array $arguments = []): array
            {
                if ($this->throwsGet) {
                    throw new \RuntimeException('no such prompt');
                }

                return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'plain fetch']]]];
            }

            public function listResources(): array
            {
                if ($this->resources === []) {
                    throw new \RuntimeException('this server has no resources');
                }

                return $this->resources;
            }

            public function readResource(string $uri): array
            {
                return ['contents' => [['uri' => $uri, 'text' => 'resource body']]];
            }
        };
    }

    /** @param list<array<mixed>> $messages */
    private function fetchingServer(array $messages): McpServer
    {
        return new class($messages) implements McpServer, McpPromptProvider {
            /** @param list<array<mixed>> $rows */
            public function __construct(private array $rows) {}

            public function start(): void {}
            public function stop(): void {}

            public function listTools(): array
            {
                return [];
            }

            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            public function listPrompts(): array
            {
                return [['name' => 'p', 'description' => 'p']];
            }

            public function getPrompt(string $name, array $arguments = []): array
            {
                return ['messages' => $this->rows];
            }
        };
    }
}

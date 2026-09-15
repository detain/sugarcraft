<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpForeignTranslate;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * E708 — an opencode `.mcp.json` pasted unchanged used to LOSE parts of
 * itself without a word: `environment` was read nowhere (searxng would spawn
 * with no `SEARXNG_URL` and nothing said so), `enabled: false` started the
 * server anyway, `type: local/remote` threw, and a `command` given as one
 * argv array reached a strict_types `TypeError`. Every row here drives the
 * canonicalisation `McpClient::startServer()` performs BEFORE the factory
 * match — since E710 the shared table it calls lives in `McpForeignTranslate`
 * — so the factory arms (pinned by DocFigure AX and E702) stay the four
 * transports the census records.
 *
 * ZERO CHILD PROCESSES: builds go through reflection on `buildServer` — the
 * pattern {@see McpClientClaudeTypeFactoryTest} established — and the rows
 * that reach `start()` address binaries that cannot exist or URLs on port 1
 * that refuse instantly, so a spawn either fails before forking or is never
 * attempted at all. Reports are asserted on their SENTENCE SHAPES, never on
 * the token or path contents of an entry.
 */
final class McpConfigToleranceTest extends TestCase
{
    /**
     * The operator's real four-server block as opencode writes it — the
     * fixture-of-record from the phase-3 compat probe, translated only in
     * shape, never in destination.
     *
     * @var array<string, array<string, mixed>>
     */
    private const OPERATOR_OPENCODE = [
        'searxng' => [
            'type' => 'local',
            'command' => ['npx', '-y', 'mcp-searxng'],
            'environment' => ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
            'enabled' => true,
        ],
        'context7' => ['type' => 'remote', 'url' => 'https://mcp.context7.com/mcp'],
        'exa' => ['type' => 'remote', 'url' => 'https://mcp.exa.ai/mcp'],
        'gh_grep' => ['type' => 'remote', 'url' => 'https://mcp.grep.app'],
    ];

    /**
     * The same four servers in the sugar-crush shape the probe wrote them in.
     * The pair above and the pair below must build indistinguishable servers.
     *
     * @var array<string, array<string, mixed>>
     */
    private const OPERATOR_TRANSLATED = [
        'searxng' => [
            'type' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', 'mcp-searxng'],
            'env' => ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
        ],
        'context7' => ['type' => 'http', 'url' => 'https://mcp.context7.com/mcp'],
        'exa' => ['type' => 'http', 'url' => 'https://mcp.exa.ai/mcp'],
        'gh_grep' => ['type' => 'http', 'url' => 'https://mcp.grep.app'],
    ];

    /** @var list<string> */
    private array $tolConfigPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tolConfigPaths as $path) {
            @unlink($path);
        }
        $this->tolConfigPaths = [];

        parent::tearDown();
    }

    // -- the block of record -------------------------------------------------

    public function testTheVerbatimOperatorOpencodeBlockBuildsTheSameFourServersAsTheTranslatedBlock(): void
    {
        $aliases = $this->tolAliases();

        foreach (self::OPERATOR_OPENCODE as $name => $foreign) {
            $type = $aliases[$foreign['type']] ?? $foreign['type'];
            $entry = $this->tolNormalize($foreign);
            self::assertIsArray($entry, "the opencode block itself skipped {$name}");
            $foreignServer = $this->tolBuild($name, $type, $entry);

            $canonical = self::OPERATOR_TRANSLATED[$name];
            $translatedEntry = $this->tolNormalize($canonical);
            self::assertIsArray($translatedEntry, "the translated block skipped {$name}");
            $translatedServer = $this->tolBuild($name, $canonical['type'], $translatedEntry);

            self::assertSame(
                $this->tolShapeOf($translatedServer),
                $this->tolShapeOf($foreignServer),
                "the opencode spelling of {$name} no longer builds the server the translated spelling builds",
            );
        }

        self::assertInstanceOf(
            StdioMcpServer::class,
            $this->tolBuild('searxng', $aliases['local'], (array) $this->tolNormalize(self::OPERATOR_OPENCODE['searxng'])),
            'the one spawned transport in the block must be the stdio server the table says `local` runs as',
        );
    }

    // -- the aliases ----------------------------------------------------------

    public function testLocalIsRenamedToStdioBeforeTheFactoryMatch(): void
    {
        $server = $this->tolBuild('renamed', $this->tolAliases()['local'], (array) $this->tolNormalize([
            'type' => 'local',
            'command' => 'echo',
        ]));

        self::assertInstanceOf(StdioMcpServer::class, $server);
        self::assertSame(
            ['local' => 'stdio', 'remote' => 'http'],
            $this->tolAliases(),
            'the alias map gained, lost, or retargeted a name — docs/MCP.md and this pin move together',
        );
    }

    public function testAnAliasedEntryIsNotReportedByAWholeConfigRunWhereTheForeignNameUsedToThrow(): void
    {
        // Pre-fix this config raised the aggregate "could not be built" throw
        // ("Unknown MCP server type: local"). Post-fix the entry builds; its
        // start attempt against a binary that cannot exist is the SILENT
        // runtime family, and silence here is the proof the rename landed.
        $client = $this->tolClientWithConfig([
            'ok-alias' => ['type' => 'local', 'command' => 'pb-tolerance-no-such-binary'],
        ]);

        $client->startServers();

        self::assertSame([], $client->startedSnapshot());
        $client->stopServers();
    }

    public function testRemoteIsRenamedToHttpByAWholeConfigRun(): void
    {
        $client = $this->tolClientWithConfig([
            'api' => ['type' => 'remote', 'url' => 'http://127.0.0.1:1/mcp'],
        ]);

        $client->startServers();

        // No throw: `remote` passed the alias gate. Nothing up: port 1 refuses.
        self::assertSame([], $client->startedSnapshot());
        $client->stopServers();
    }

    // -- the command array -----------------------------------------------------

    public function testArrayCommandBecomesProgramPlusArgvTail(): void
    {
        $entry = $this->tolNormalize([
            'type' => 'local',
            'command' => ['npx', '-y', 'mcp-searxng'],
        ]);

        self::assertIsArray($entry);
        self::assertSame('npx', $entry['command']);
        self::assertSame(['-y', 'mcp-searxng'], $entry['args']);
    }

    public function testArrayCommandTailJoinsAheadOfExistingArgs(): void
    {
        // The array is a whole argv and the `args` key EXTENDS it — putting
        // the user's extra args between the program and its own tail would
        // hand `npx` its flags as the package name.
        $entry = $this->tolNormalize([
            'command' => ['npx', '-y', 'pkg'],
            'args' => ['/srv/data'],
        ]);

        self::assertIsArray($entry);
        self::assertSame('npx', $entry['command']);
        self::assertSame(['-y', 'pkg', '/srv/data'], $entry['args']);
    }

    public function testStringCommandAndArgsRideThroughUnchanged(): void
    {
        $canonical = ['command' => 'npx', 'args' => ['-y', 'x']];

        self::assertSame($canonical, $this->tolNormalize($canonical));
    }

    public function testARawArrayCommandStillRaisesTypeErrorAgainstTheFactoryDirect(): void
    {
        // The regression half of the fix: this is WHY the canonicalisation
        // must precede dispatch — `private string $command` on the transport
        // answers a JSON array with a strict_types TypeError. The guard turns
        // that into a per-entry report either way, but a report that says
        // "must be of type string, array given" blames the operator for a
        // shape the reader now accepts.
        try {
            $this->tolBuild('raw', 'stdio', ['command' => ['npx', '-y']]);
        } catch (\TypeError $e) {
            self::assertStringContainsString('must be of type string', $e->getMessage());

            return;
        }

        self::fail('buildServer() swallowed an array command — the pre-E708 TypeError route is gone and the regression lost its subject');
    }

    public function testMalformedCommandArraysCostAReportLineNotASpawn(): void
    {
        $client = $this->tolClientWithConfig([
            'empty-list' => ['command' => []],
            'named-list' => ['command' => ['bin', 'tail', 'extra' => 'oops']],
            'int-head' => ['command' => [5, '-y']],
            'int-tail' => ['command' => ['npx', 7]],
            'map-args' => ['command' => ['npx'], 'args' => ['k' => 'v']],
        ]);

        $reported = $this->tolAggregateMessage($client);

        self::assertStringContainsString('empty-list ("command" array must be a non-empty list)', $reported);
        self::assertStringContainsString('named-list ("command" array must be a non-empty list)', $reported);
        self::assertStringContainsString('int-head ("command" array must start with a non-empty string)', $reported);
        self::assertStringContainsString('int-tail ("command" array must hold strings)', $reported);
        self::assertStringContainsString('map-args ("args" must be a list)', $reported);
        self::assertStringContainsString('5 MCP server entries', $reported);
    }

    // -- environment / env precedence -------------------------------------------

    public function testEnvironmentMapBecomesEnvWhenEnvIsAbsent(): void
    {
        $entry = $this->tolNormalize([
            'command' => 'run',
            'environment' => ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
        ]);

        self::assertIsArray($entry);
        self::assertArrayNotHasKey('environment', $entry);
        self::assertSame(
            ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
            $entry['env'],
            'the opencode env map no longer lands where resolveEnv reads',
        );

        // r81-rv MINOR-1: `"env": null` is a declared-but-empty slot, not the
        // explicit spelling that wins precedence — `environment` must survive,
        // never both maps die to the key's mere presence.
        $nullSlot = $this->tolNormalize([
            'command' => 'run',
            'env' => null,
            'environment' => ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
        ]);

        self::assertIsArray($nullSlot);
        self::assertSame(
            ['SEARXNG_URL' => 'http://skynet2.interserver.net:8080/'],
            $nullSlot['env'],
            'a null "env" must not silence a live "environment" map',
        );
        self::assertArrayNotHasKey('environment', $nullSlot, 'the winner still leaves the loser un-set for a second reader');
    }

    public function testEnvWinsWhenBothSpellingsArePresent(): void
    {
        $entry = $this->tolNormalize([
            'command' => 'run',
            'env' => ['KEEP' => 'mine'],
            'environment' => ['DROP' => 'theirs'],
        ]);

        self::assertIsArray($entry);
        self::assertSame(['KEEP' => 'mine'], $entry['env']);
        self::assertArrayNotHasKey('environment', $entry, 'an environment map that lost the precedence must not linger for a second reader');
    }

    public function testInterpolationRidesTheRenamedEnvironmentMap(): void
    {
        putenv('PB_TOLERANCE_SECRET=resolved-value');

        try {
            $server = $this->tolBuild('interp', 'stdio', (array) $this->tolNormalize([
                'command' => 'run',
                'environment' => ['TOKEN' => '${PB_TOLERANCE_SECRET}'],
            ]));
        } finally {
            putenv('PB_TOLERANCE_SECRET');
        }

        self::assertInstanceOf(StdioMcpServer::class, $server);
        self::assertSame(
            ['TOKEN' => 'resolved-value'],
            $this->tolEnv($server),
            'a renamed environment map must reach the same ${VAR} expansion a spelled `env` gets — the silent-drop class this issue exists for',
        );
    }

    // -- enabled ------------------------------------------------------------------

    public function testEnabledFalseSkipsTheEntryAndNamesItInItsOwnSentence(): void
    {
        $client = $this->tolClientWithConfig([
            'lone' => ['type' => 'local', 'command' => 'pb-tolerance-no-such-binary', 'enabled' => false],
        ]);

        $reported = $this->tolAggregateMessage($client);

        self::assertStringContainsString('1 MCP server entry in this config is disabled and skipped: lone', $reported);
        self::assertStringNotContainsString('could not be built', $reported, 'a deliberate skip must not be phrased as a build failure');
        self::assertArrayNotHasKey('lone', $client->startedSnapshot());
        $client->stopServers();
    }

    public function testDisabledSkipAndBuildFailureReportInSeparateSentences(): void
    {
        $client = $this->tolClientWithConfig([
            'off' => ['type' => 'http', 'url' => 'http://127.0.0.1:1/mcp', 'enabled' => false],
            'bad' => ['type' => 'nope'],
        ]);

        $reported = $this->tolAggregateMessage($client);

        self::assertStringContainsString('1 MCP server entry in this config could not be built: bad (Unknown MCP server type: nope)', $reported);
        self::assertStringContainsString('1 MCP server entry in this config is disabled and skipped: off', $reported);
        self::assertMatchesRegularExpression(
            '/could not be built: [^.]+\. \d+ MCP server entry/',
            $reported,
            'the two sentences must stay separately readable — one list carrying both would be a new lie',
        );
        $client->stopServers();
    }

    public function testEnabledTrueOrAbsentStillAttemptsTheStartAndIsNotReported(): void
    {
        $client = $this->tolClientWithConfig([
            'explicit-yes' => ['type' => 'http', 'url' => 'http://127.0.0.1:1/mcp', 'enabled' => true],
            'bare' => ['type' => 'http', 'url' => 'http://127.0.0.1:1/mcp'],
        ]);

        $client->startServers(); // no throw at all: nothing was skipped, nothing failed to build

        self::assertSame([], $client->startedSnapshot()); // refused connects are the silent runtime family
        $client->stopServers();
    }

    public function testANonBooleanEnabledCostsAPerEntryReportLine(): void
    {
        $client = $this->tolClientWithConfig([
            'string-flag' => ['type' => 'http', 'url' => 'http://127.0.0.1:1/mcp', 'enabled' => 'false'],
        ]);

        $reported = $this->tolAggregateMessage($client);

        self::assertStringContainsString('string-flag ("enabled" must be a boolean when present)', $reported);
        self::assertStringNotContainsString('disabled and skipped', $reported, 'an unusable flag is a config error, never a honoured decision');
    }

    // -- harness --------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function tolAliases(): array
    {
        $aliases = (new \ReflectionClass(McpForeignTranslate::class))->getConstant('TYPE_ALIASES');
        self::assertIsArray($aliases, 'McpForeignTranslate::TYPE_ALIASES vanished — these rows lost their subject');

        /** @var array<string, string> $aliases */
        return $aliases;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function tolNormalize(array $config): ?array
    {
        // E710: the canonicaliser moved from a private McpClient method to
        // the public shared table the loader calls — no reflection needed any
        // more, and the parity these rows prove now runs through the SAME
        // code path `mcp import` translates with.
        return McpForeignTranslate::normalizeEntry($config);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function tolBuild(string $name, string $type, array $entry): McpServer
    {
        $build = new \ReflectionMethod(McpClient::class, 'buildServer');

        return $build->invoke($this->tolFreshClient(), $name, $type, $entry);
    }

    private function tolFreshClient(): McpClient
    {
        return new McpClient($this->tolConfigPath('unused'));
    }

    /**
     * A client over a written `.mcp.json`, so `startServers()` runs the whole
     * real path: read, alias, canonicalise, build, attempt, report.
     *
     * @param array<string, array<string, mixed>> $servers
     */
    private function tolClientWithConfig(array $servers): McpClient
    {
        $path = $this->tolConfigPath('written');
        file_put_contents($path, json_encode(['mcpServers' => $servers], \JSON_THROW_ON_ERROR));

        return new McpClient($path);
    }

    private function tolConfigPath(string $role): string
    {
        $path = sys_get_temp_dir() . '/pb_tol_' . $role . '_' . getmypid() . '_' . bin2hex(random_bytes(5)) . '.json';
        if ('written' === $role) {
            $this->tolConfigPaths[] = $path;
        }

        return $path;
    }

    /**
     * Drive `startServers()` to its aggregate report and hand back the message.
     * Failing loudly when nothing was reported: every caller here asserts on
     * the report existing at all.
     */
    private function tolAggregateMessage(McpClient $client): string
    {
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        self::fail('startServers() reported nothing for a config these rows promise to report');
    }

    /**
     * @return array<string, mixed>
     */
    private function tolShapeOf(McpServer $server): array
    {
        if ($server instanceof StdioMcpServer) {
            return [
                'class' => StdioMcpServer::class,
                'command' => $this->tolCommand($server),
                'args' => $this->tolArgs($server),
                'env' => $this->tolEnv($server),
            ];
        }

        if ($server instanceof HttpMcpServer) {
            return ['class' => HttpMcpServer::class, 'url' => $this->tolUrl($server)];
        }

        return ['class' => $server::class];
    }

    private function tolCommand(McpServer $server): string
    {
        return (string) (new \ReflectionProperty($server, 'command'))->getValue($server);
    }

    /** @return list<string> */
    private function tolArgs(McpServer $server): array
    {
        /** @var list<string> $args */
        $args = (new \ReflectionProperty($server, 'args'))->getValue($server);

        return $args;
    }

    /** @return array<string, string> */
    private function tolEnv(McpServer $server): array
    {
        /** @var array<string, string> $env */
        $env = (new \ReflectionProperty($server, 'env'))->getValue($server);

        return $env;
    }

    private function tolUrl(McpServer $server): string
    {
        return (string) (new \ReflectionProperty($server, 'url'))->getValue($server);
    }
}

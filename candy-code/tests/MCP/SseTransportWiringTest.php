<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpForeignTranslate;
use SugarCraft\Code\MCP\SseMcpServer;

/**
 * MCP-4: the `sse` factory route end-to-end, config to constructed transport.
 *
 * The transport itself is pinned by {@see SseMcpServerTest}; what lives here
 * is the WIRING the docs page promises: `buildServer`'s fifth arm reads
 * exactly `url`/`headers`/`toolTimeout`, `resolveEnv` interpolates the sse
 * headers map like http's, a foreign Claude document carrying
 * `"type": "sse"` imports NOTE-FREE and builds a live SseMcpServer class,
 * and the config-error report names only genuinely unknown types — sse is no
 * longer the named offender (the route-2 history in
 * {@see McpClientServerIsolationTest} records when it was). The auth-refusal
 * row's `transport` key is pinned at source-slice level (its behaviour half
 * is HttpMcpToolTimeoutTest's exact-array pin) because driving it through
 * `startServers` would need a listening socket inside a single-threaded
 * launch — the shape that deadlocks.
 */
final class SseTransportWiringTest extends TestCase
{
    /** @var list<string> */
    private array $configs = [];

    /** @var list<string> */
    private array $envKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
        }
        $this->envKeys = [];

        foreach ($this->configs as $path) {
            @unlink($path);
        }
        $this->configs = [];

        parent::tearDown();
    }

    public function testTheFactoryArmThreadsUrlInterpolatedHeadersAndTheToolCeiling(): void
    {
        $key = 'W5H_SSE_TOKEN_' . strtoupper(bin2hex(random_bytes(4)));
        putenv($key . '=carried');
        $this->envKeys[] = $key;

        $server = $this->buildServer('wired', 'sse', [
            'url' => 'http://legacy.example/sse',
            'headers' => ['Authorization' => 'Bearer ${' . $key . '}', 'X-Plain' => 'plain'],
            'toolTimeout' => 300,
        ]);

        self::assertInstanceOf(SseMcpServer::class, $server);

        $mirror = new ReflectionObject($server);
        self::assertSame('http://legacy.example/sse', $mirror->getProperty('url')->getValue($server));
        self::assertSame(
            ['Authorization' => 'Bearer carried', 'X-Plain' => 'plain'],
            $mirror->getProperty('headers')->getValue($server),
            'the sse arm must interpolate its headers map exactly like the http arm',
        );
        self::assertSame(300.0, $mirror->getProperty('toolTimeoutSeconds')->getValue($server));
    }

    public function testNonsenseToolTimeoutFallsBackToTheSseDefaultNotToZero(): void
    {
        $server = $this->buildServer('dulled', 'sse', [
            'url' => 'http://legacy.example/sse',
            'toolTimeout' => '-5',
        ]);

        $mirror = new ReflectionObject($server);
        self::assertSame(
            SseMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS,
            $mirror->getProperty('toolTimeoutSeconds')->getValue($server),
            'a hand-edited nonsense ceiling costs the default, never a zero bound',
        );
    }

    public function testAForeignClaudeSseEntryImportsNoteFreeAndBuildsTheTransport(): void
    {
        $translated = McpForeignTranslate::translateDocument('claude', [
            'mcpServers' => [
                'legacy' => ['type' => 'sse', 'url' => 'http://127.0.0.1:9/sse'],
            ],
        ]);

        self::assertSame([], $translated['notes'], 'MCP-4 built the transport — the import-time apology note is gone');

        $entry = $translated['servers']['legacy'];
        self::assertSame('sse', $entry['type'], 'no alias: sse arrives as the native type it now IS');

        $canonical = McpForeignTranslate::normalizeEntry($entry);
        self::assertNotNull($canonical);
        self::assertInstanceOf(SseMcpServer::class, $this->buildServer('legacy', (string) $canonical['type'], $canonical));
    }

    public function testAGenuinelyUnknownTypeStillThrowsWithItsOwnName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server type: websocket');

        $this->buildServer('space-age', 'websocket', ['url' => 'http://legacy.example/ws']);
    }

    public function testTheBuildFailureReportNamesTheOffendingTypeAndNeverTheSseEntry(): void
    {
        $client = new McpClient($this->configFile([
            'fine' => ['type' => 'sse', 'url' => 'http://127.0.0.1:9/sse'],
            'bad' => ['type' => 'websocket', 'url' => 'http://legacy.example/ws'],
        ]), $this->mockClient(), null, true);

        $failure = null;
        try {
            $client->startServers();
        } catch (RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'a websocket entry must still surface as a build failure');
        self::assertStringContainsString('bad (Unknown MCP server type: websocket)', $failure);
        self::assertStringNotContainsString('fine', $failure,
            'the sse entry built — only genuinely unknown types belong in the report');

        // The sse entry survived its own start: the connect to the RFC-closed
        // port 9 is refused, which is the RUNTIME silent-skip class, not a
        // config failure — and the snapshot stays empty either way.
        self::assertSame([], $client->startedSnapshot());
        self::assertSame([], $client->authRequired());
    }

    // =========================================================================
    // Source-slice pins: the rows behind behaviours this file cannot drive
    // without a listening socket inside a single-threaded launch.
    // =========================================================================

    public function testTheAuthRefusalRowCarriesTheTransportAndTheSnapshotReadsIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/MCP/McpClient.php');

        $startServer = self::methodSpan($source, 'private function startServer(');
        self::assertStringContainsString(
            "\$server instanceof SseMcpServer ? 'sse' : 'http'",
            $startServer,
            'an sse credential refusal must report itself as sse, defaulting pre-MCP-4 rows to http',
        );

        $snapshot = self::methodSpan($source, 'public function startedSnapshot(');
        self::assertStringContainsString('SseMcpServer', $snapshot);
        self::assertStringContainsString("\$row['transport'] ?? 'http'", $snapshot);

        $factory = self::methodSpan($source, 'private function buildServer(');
        self::assertStringContainsString("'sse' => new SseMcpServer(", $factory);
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function buildServer(string $name, string $type, array $config): object
    {
        $client = new McpClient('/does/not/matter.json', $this->mockClient(), null, true);

        return (new ReflectionMethod($client, 'buildServer'))->invoke($client, $name, $type, $config);
    }

    private function mockClient(): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]);
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     */
    private function configFile(array $servers): string
    {
        $path = sys_get_temp_dir() . '/w5h_wiring_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, (string) json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR));
        $this->configs[] = $path;

        return $path;
    }

    /**
     * The text of one method, cut at the next sibling declaration — the
     * house source-slice shape (DocFigure arms use the same walk).
     */
    private static function methodSpan(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertIsInt($start, "the source slice names a method that moved or vanished: {$signature}");
        $end = strpos($source, "\n    public function ", $start + strlen($signature));
        self::assertIsInt($end, "the source slice for {$signature} runs past the last method");

        return substr($source, $start, $end - $start);
    }
}

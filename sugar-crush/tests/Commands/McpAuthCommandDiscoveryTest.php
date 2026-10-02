<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;
use SugarCraft\Crush\MCP\OAuthDiscovery;

/**
 * Audit MCP-6, the `mcp auth add` half and the fetcher itself.
 *
 * `add` used to build its own `<server>/.well-known/...` URL — the same
 * path-appended mistake as the login flow, in a second copy. It now runs
 * {@see OAuthDiscovery}, so the URLs it asks for are exactly the shared
 * candidate order. And {@see McpAuthCommand::fetchOAuthMetadata()} used to
 * hand a 401's JSON error body back as if it were metadata; a non-2xx status
 * now throws, which discovery reads as "not here, try the next candidate".
 *
 * The fetcher leg talks to a `php -S` child bound to 127.0.0.1 on a port this
 * test picked, started per test and killed by PID in tearDown — loopback
 * only, never the network.
 */
final class McpAuthCommandDiscoveryTest extends TestCase
{
    private string $tempDir;
    private string $authFilePath;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string|false $savedNoProxy = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/mcp_add_discovery_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->authFilePath = $this->tempDir . '/auth.json';
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        $this->server = null;

        if ($this->savedNoProxy !== false) {
            putenv('no_proxy=' . $this->savedNoProxy);
        } else {
            putenv('no_proxy');
        }

        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testAddDiscoversThroughTheSharedRfc8414OrderForAPathBearingUrl(): void
    {
        $history = [];
        $asked = [];
        $command = $this->command([
            new Response(200, [], (string) json_encode(['client_id' => 'cid-add6', 'registration_access_token' => 'reg-add6'])),
            new Response(200, [], (string) json_encode(['access_token' => 'at-add6', 'expires_in' => 3600])),
        ], $history, [
            'https://h/.well-known/oauth-authorization-server' => [
                'registration_endpoint' => 'https://h/register',
                'token_endpoint' => 'https://h/token',
            ],
        ], $asked);

        ob_start();
        $rc = $command->execute(new Chat([]), ['add', 'https://h/mcp']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertSame(
            [...OAuthDiscovery::protectedResourceCandidates('https://h/mcp'), ...OAuthDiscovery::authorizationServerCandidates('https://h/mcp')],
            $asked,
            'add asks exactly the shared discovery order — no private copy of the URL construction',
        );
        self::assertSame('https://h/register', (string) $history[0]['request']->getUri());
        self::assertSame('https://h/token', (string) $history[1]['request']->getUri());

        $persisted = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();
        self::assertSame(['https://h/mcp'], array_keys($persisted), 'stored under the exact URL the user passed');
    }

    public function testAnExplicitRegistrationUrlStillLetsDiscoveryFillTheTokenUrl(): void
    {
        $history = [];
        $asked = [];
        $command = $this->command([
            new Response(200, [], (string) json_encode(['client_id' => 'cid-mix', 'registration_access_token' => 'reg-mix'])),
            new Response(200, [], (string) json_encode(['access_token' => 'at-mix'])),
        ], $history, [
            'https://h/.well-known/oauth-authorization-server' => [
                'registration_endpoint' => 'https://h/discovered-register',
                'token_endpoint' => 'https://h/token',
            ],
        ], $asked);

        ob_start();
        $rc = $command->execute(new Chat([]), ['add', 'https://h/mcp', 'https://h/manual-register']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertSame('https://h/manual-register', (string) $history[0]['request']->getUri(), 'the operand wins over discovery');
        self::assertSame('https://h/token', (string) $history[1]['request']->getUri());
    }

    public function testBothOperandsSkipDiscovery(): void
    {
        $history = [];
        $asked = [];
        $command = $this->command([
            new Response(200, [], (string) json_encode(['client_id' => 'cid-op', 'registration_access_token' => 'reg-op'])),
            new Response(200, [], (string) json_encode(['access_token' => 'at-op'])),
        ], $history, [], $asked);

        ob_start();
        $rc = $command->execute(new Chat([]), ['add', 'https://h/mcp', 'https://h/register', 'https://h/token']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertSame([], $asked);
    }

    public function testFetchOAuthMetadataReturnsA200JsonDocument(): void
    {
        $this->startFakeServer();

        self::assertSame(
            ['token_endpoint' => 'https://h/token'],
            McpAuthCommand::fetchOAuthMetadata("http://127.0.0.1:{$this->port}/ok"),
        );
    }

    public function testFetchOAuthMetadataRefusesA401JsonErrorBody(): void
    {
        $this->startFakeServer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        McpAuthCommand::fetchOAuthMetadata("http://127.0.0.1:{$this->port}/mcp/.well-known/oauth-authorization-server");
    }

    public function testFetchOAuthMetadataRefusesA404JsonBody(): void
    {
        $this->startFakeServer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        McpAuthCommand::fetchOAuthMetadata("http://127.0.0.1:{$this->port}/missing");
    }

    /**
     * @param list<Response>                      $responses
     * @param list<array<string, mixed>>          $history
     * @param array<string, array<string, mixed>> $table
     * @param list<string>                        $asked
     */
    private function command(array $responses, array &$history, array $table, array &$asked): McpAuthCommand
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $store = new McpAuthStore(new OAuthClientRegistration(new Client(['handler' => $stack]), $this->authFilePath));

        return new McpAuthCommand($store, static function (string $url) use ($table, &$asked): array {
            $asked[] = $url;
            if (!isset($table[$url])) {
                throw new \RuntimeException("HTTP 401 from {$url}");
            }

            return $table[$url];
        });
    }

    /**
     * A `php -S` loopback server: `/ok` answers 200 with metadata, `/missing`
     * 404 with a JSON body, everything else 401 with a JSON error body — the
     * shape the audit measured on a real MCP endpoint.
     */
    private function startFakeServer(): void
    {
        $router = $this->tempDir . '/router.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            header('Content-Type: application/json');
            if ($path === '/ok') {
                echo json_encode(['token_endpoint' => 'https://h/token']);
            } elseif ($path === '/missing') {
                http_response_code(404);
                echo json_encode(['error' => 'not_found']);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'invalid_token']);
            }
            PHP);

        // Pick a free loopback port, then hand it to the child. The window
        // between close and bind is tiny and loopback-only.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        // A proxy in the environment must never see the loopback request.
        $this->savedNoProxy = getenv('no_proxy');
        putenv('no_proxy=127.0.0.1');

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->tempDir,
        );
        self::assertIsResource($server);
        $this->server = $server;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }

        self::fail("php -S did not start listening on 127.0.0.1:{$this->port}");
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

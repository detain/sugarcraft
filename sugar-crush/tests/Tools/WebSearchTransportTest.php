<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;

/**
 * WebSearch's real transport and endpoint guard (audit F-W3, part a).
 *
 * {@see WebSearchToolTest} stubs `fetch()` and so cannot see the wire: these
 * tests drive the production `fetch()` against a forked loopback fixture,
 * with a fake resolver mapping a made-up name to 127.0.0.2 and a blocklist
 * that admits only that literal, so nothing leaves the machine and nothing
 * else is dialable even if a seam regressed. The guard tests run the
 * PRODUCTION blocklist (WebFetch's) through a fake resolver.
 */
final class WebSearchTransportTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private const FIXTURE_ADDRESS = '127.0.0.2';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcrush_websearch_' . uniqid((string) getmypid(), true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Reap first: a fixture child may still be writing its logs here.
        $this->reapTrackedForkedChildren();

        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/' . chr(42)));
            rmdir($this->tmpDir);
        }
    }

    public function testARedirectIsRefusedAndItsTargetNeverDialed(): void
    {
        // The wrapper used to follow up to 20 redirects with no address
        // re-check. The fixture would answer a second connection, so a
        // followed 302 shows up as a second request log.
        $port = $this->startFixture([
            $this->response('', 302, ['Location: http://' . self::FIXTURE_ADDRESS . ':{PORT}/elsewhere']),
            $this->response('{"results":[]}'),
        ]);

        $result = $this->fixtureSearch($port)->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('HTTP 302', $result->content());
        $this->assertStringContainsString('/elsewhere', $result->content());
        $this->assertFileExists($this->tmpDir . '/request-0.log');
        $this->assertFileDoesNotExist($this->tmpDir . '/request-1.log', 'a redirect must never be followed');
    }

    public function testTheSocketDialsTheVettedLiteralAndKeepsTheName(): void
    {
        $port = $this->startFixture([
            $this->response((string) json_encode(['results' => [['title' => 'PHP', 'url' => 'https://php.net']]])),
        ]);

        $result = $this->fixtureSearch($port)->execute(['query' => 'php', 'description' => 'test']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('https://php.net', $result->content());
        $request = (string) file_get_contents($this->tmpDir . '/request-0.log');
        $this->assertMatchesRegularExpression('#^GET /search\?q=php&format=json HTTP/1\.[01]\r\n#', $request);
        $this->assertStringContainsString("Host: search.test:$port\r\n", $request);
    }

    public function testAnOversizedBodyIsAbandonedMidStream(): void
    {
        // The cap used to apply after file_get_contents() had buffered the
        // whole body. The fixture streams far past the 5 MB cap and records
        // how much the client accepted before closing; a bounded reader
        // leaves most of it unsent.
        $total = 48 * 1024 * 1024;
        $port = $this->startStreamingFixture($total);

        $result = $this->fixtureSearch($port)->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('exceeds maximum size', $result->content());
        $this->assertTrue($this->waitForFile($this->tmpDir . '/sent.log'), 'the streaming fixture never reported');
        $sent = (int) file_get_contents($this->tmpDir . '/sent.log');
        $this->assertLessThan($total, $sent, 'the client read the whole stream instead of stopping at the cap');
    }

    public function testAnAaaaOnlyLoopbackNameIsRefused(): void
    {
        // gethostbyname() answers v4 only, so the old guard read this name as
        // unresolvable and therefore "not blocked".
        $result = $this->guardedSearch('http://v6only.test/search', ['v6only.test' => ['::1']])
            ->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private', $result->content());
    }

    public function testANameMixingPublicAndPrivateAnswersIsRefused(): void
    {
        $result = $this->guardedSearch('https://mixed.test/search', ['mixed.test' => ['93.184.215.14', '10.0.0.5']])
            ->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private', $result->content());
    }

    /**
     * Ranges the old private copy of the list lacked; WebFetch's has them.
     *
     * @return iterable<string, array{string}>
     */
    public static function rangesTheOldListMissed(): iterable
    {
        yield 'this host' => ['0.0.0.0'];
        yield 'alibaba metadata (cgnat)' => ['100.100.100.200'];
        yield 'benchmarking' => ['198.18.0.1'];
        yield 'mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'nat64 loopback' => ['64:ff9b::7f00:1'];
    }

    /** @dataProvider rangesTheOldListMissed */
    public function testTheEndpointGuardUsesWebFetchsFullRangeList(string $address): void
    {
        $result = $this->guardedSearch('http://search.test/search', ['search.test' => [$address]])
            ->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError(), "$address must be refused");
        $this->assertStringContainsString('private', $result->content());
    }

    public function testAnUnresolvableEndpointIsRefusedRatherThanDialedByName(): void
    {
        $result = $this->guardedSearch('http://nowhere.test/search', [])
            ->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('could not resolve search endpoint host: nowhere.test', $result->content());
    }

    public function testAResolverAnswerThatIsNotAnIpLiteralIsRefused(): void
    {
        $result = $this->guardedSearch('http://odd.test/search', ['odd.test' => ['evil.example']])
            ->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('could not resolve', $result->content());
    }

    public function testAHostlessEndpointIsRefused(): void
    {
        $result = (new WebSearch('http:///search'))->execute(['query' => 'php', 'description' => 'test']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not a valid URL', $result->content());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fixtureSearch(int $port): WebSearch
    {
        return new WebSearch(
            "http://search.test:$port/search",
            timeout: 10,
            resolveAddresses: static fn (string $host): array => $host === 'search.test' ? [self::FIXTURE_ADDRESS] : [],
            isBlockedAddress: static fn (string $address): bool => $address !== self::FIXTURE_ADDRESS,
        );
    }

    /**
     * A WebSearch with the production blocklist and a fake resolver, whose
     * fetch fails the test: every case using it must be refused first.
     *
     * @param array<string, list<string>> $dns
     */
    private function guardedSearch(string $endpoint, array $dns): WebSearch
    {
        return new class ($endpoint, $dns) extends WebSearch {
            /** @param array<string, list<string>> $dns */
            public function __construct(string $endpoint, array $dns)
            {
                parent::__construct(
                    $endpoint,
                    resolveAddresses: static fn (string $host): array => $dns[$host] ?? [],
                );
            }

            protected function fetch(string $url, string $dialAddress): array
            {
                throw new \LogicException("the guard admitted $dialAddress and dialed it");
            }
        };
    }

    /** @param list<string> $extraHeaders */
    private function response(string $body, int $code = 200, array $extraHeaders = []): string
    {
        $status = $code === 200 ? '200 OK' : "$code Found";

        return "HTTP/1.1 $status\r\n"
            . implode('', array_map(static fn (string $h): string => "$h\r\n", $extraHeaders))
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }

    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the loopback fixtures');
        }
    }

    /**
     * @return array{0: resource, 1: int} listening socket and its ephemeral port
     */
    private function bindFixture(): array
    {
        $this->requireFork();
        // Port 0: fixed test ports collide with other users' listeners on
        // this shared box. 127.0.0.2 so the real blocklist would still refuse
        // it if a seam were ever widened.
        $server = @stream_socket_server('tcp://' . self::FIXTURE_ADDRESS . ':0', $errno, $errstr);
        $this->assertIsResource($server, "fixture bind failed: $errstr");
        $name = (string) stream_socket_get_name($server, false);

        return [$server, (int) substr($name, strrpos($name, ':') + 1)];
    }

    /**
     * Serves $responses in order, one per connection, logging each request
     * to request-<n>.log. `{PORT}` in a response becomes the fixture's port.
     *
     * @param non-empty-list<string> $responses
     */
    private function startFixture(array $responses): int
    {
        [$server, $port] = $this->bindFixture();
        $responses = array_map(static fn (string $r): string => str_replace('{PORT}', (string) $port, $r), $responses);
        $logDir = $this->tmpDir;

        $pid = $this->forkTracked();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed for the loopback fixture');
        }
        if ($pid === 0) {
            foreach ($responses as $index => $response) {
                // 3 s, not longer: a connection the client never makes (the
                // refused redirect) must not keep the child alive for long.
                $connection = @stream_socket_accept($server, 3.0);
                if ($connection === false) {
                    continue;
                }
                stream_set_timeout($connection, 5);
                $request = '';
                while (!feof($connection) && !str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($connection, 4096);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                file_put_contents("$logDir/request-$index.log", $request);
                @fwrite($connection, $response);
                fclose($connection);
            }
            fclose($server);
            ForkedChild::exitNow(0);
        }

        fclose($server);

        return $port;
    }

    /**
     * Streams a $total-byte 200 body (announced by Content-Length) until the
     * client hangs up, then writes how many body bytes were accepted to
     * sent.log.
     */
    private function startStreamingFixture(int $total): int
    {
        [$server, $port] = $this->bindFixture();
        $logDir = $this->tmpDir;

        $pid = $this->forkTracked();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed for the streaming fixture');
        }
        if ($pid === 0) {
            $connection = @stream_socket_accept($server, 10.0);
            $sent = 0;
            if ($connection !== false) {
                stream_set_timeout($connection, 5);
                $request = '';
                while (!feof($connection) && !str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($connection, 4096);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: $total\r\nConnection: close\r\n\r\n");
                $block = str_repeat('x', 65536);
                while ($sent < $total) {
                    $written = @fwrite($connection, $block);
                    if ($written === false || $written === 0) {
                        break;
                    }
                    $sent += $written;
                }
                fclose($connection);
            }
            file_put_contents("$logDir/sent.log.tmp", (string) $sent);
            rename("$logDir/sent.log.tmp", "$logDir/sent.log");
            fclose($server);
            ForkedChild::exitNow(0);
        }

        fclose($server);

        return $port;
    }

    private function waitForFile(string $path): bool
    {
        $deadline = microtime(true) + 10.0;
        while (!is_file($path)) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(20_000);
        }

        return true;
    }
}

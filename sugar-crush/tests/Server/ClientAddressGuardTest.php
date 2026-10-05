<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Crush\Server\Http\ClientAddress;
use SugarCraft\Crush\Server\Http\ClientAddressGuard;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\ServerConfigException;

/**
 * `--allowed-ips` / `server.allowedIps`: the client-address allow-list that
 * runs ahead of the Host/Origin checks and every credential.
 */
final class ClientAddressGuardTest extends TestCase
{
    private bool $passed = false;

    /** @var list<string> */
    private array $logged = [];

    /** @param array<string, string> $headers */
    private function guarded(ServerConfig $config, string $peer, array $headers = [], string $method = 'GET'): ResponseInterface
    {
        $this->passed = false;
        $this->logged = [];
        $guard = new ClientAddressGuard($config, function (string $line): void {
            $this->logged[] = $line;
        });
        $request = new ServerRequest($method, 'http://127.0.0.1:7420/api/login', $headers + ['Host' => '127.0.0.1:7420'], '', '1.1', ['REMOTE_ADDR' => $peer]);
        $result = $guard($request, function (): ResponseInterface {
            $this->passed = true;

            return new Response(204);
        });
        self::assertInstanceOf(ResponseInterface::class, $result);

        return $result;
    }

    private static function config(string ...$ips): ServerConfig
    {
        return ServerConfig::new('/nonexistent')->withAllowedIps($ips);
    }

    public function testAnEmptyListFiltersNothing(): void
    {
        self::assertSame(204, $this->guarded(self::config(), '198.51.100.4')->getStatusCode());
        self::assertSame(204, $this->guarded(self::config(), '')->getStatusCode(), 'off means off, even for an unknown peer');
    }

    public function testListedAddressesAndRangesAreAdmitted(): void
    {
        $config = self::config('203.0.113.7', '10.0.0.0/8', '2001:db8::/32');

        foreach (['203.0.113.7', '10.1.2.3', '10.255.255.255', '2001:db8::42', '2001:DB8:0:0::9'] as $peer) {
            self::assertSame(204, $this->guarded($config, $peer)->getStatusCode(), $peer);
            self::assertTrue($this->passed);
        }
    }

    public function testAnUnlistedAddressIsRefused403BeforeAnythingElse(): void
    {
        $response = $this->guarded(self::config('203.0.113.7', '10.0.0.0/8'), '198.51.100.4', ['Authorization' => 'Bearer whatever']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->passed, 'the request never reaches the next middleware');
        self::assertSame(
            ['error' => [
                'kind' => 'ip_refused',
                'message' => 'this server does not accept connections from 198.51.100.4; start it with --allowed-ips 198.51.100.4 or add it to server.allowedIps',
            ]],
            \json_decode((string) $response->getBody(), true),
        );
        self::assertSame(['refused client 198.51.100.4: not in --allowed-ips / server.allowedIps'], $this->logged);
        self::assertSame(403, $this->guarded(self::config('10.0.0.0/8'), '11.0.0.1')->getStatusCode(), 'the prefix is honoured');
        self::assertSame(403, $this->guarded(self::config('10.0.0.0/8'), '2001:db8::1')->getStatusCode(), 'an IPv6 client never matches an IPv4 range');
    }

    public function testAWebSocketUpgradeIsRefusedTheSameWay(): void
    {
        $response = $this->guarded(self::config('203.0.113.7'), '198.51.100.4', ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Origin' => 'http://127.0.0.1:7420']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('ip_refused', (string) $response->getBody());
        self::assertFalse($this->passed);
    }

    public function testLoopbackIsAlwaysAdmitted(): void
    {
        $config = self::config('203.0.113.7');
        foreach (['127.0.0.1', '127.8.9.10', '::1', '::ffff:127.0.0.1'] as $peer) {
            self::assertSame(204, $this->guarded($config, $peer)->getStatusCode(), $peer);
        }
    }

    public function testAnUnknownPeerIsRefused(): void
    {
        $response = $this->guarded(self::config('203.0.113.7'), '');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('from an unknown address', (string) $response->getBody());
    }

    public function testIpv4MappedAddressesCountAsTheirIpv4OnBothSides(): void
    {
        self::assertSame(204, $this->guarded(self::config('203.0.113.7'), '::ffff:203.0.113.7')->getStatusCode(), 'a dual-stack bind reports a mapped peer');
        self::assertSame(204, $this->guarded(self::config('::ffff:10.0.0.0/104'), '10.9.8.7')->getStatusCode(), 'a mapped range is its IPv4 range');
        self::assertSame(['10.0.0.0/8', '203.0.113.7', '2001:db8::/32'], self::config('::ffff:10.0.0.0/104', '::FFFF:203.0.113.7', '2001:DB8::/32', '203.0.113.7/32')->allowedIps);

        $response = $this->guarded(self::config('203.0.113.7'), '::ffff:198.51.100.4');
        self::assertStringContainsString('connections from 198.51.100.4;', (string) $response->getBody(), 'the refusal names the IPv4 address');
    }

    public function testBehindATrustedProxyTheForwardedClientIsChecked(): void
    {
        $config = self::config('203.0.113.7')->withTrustedProxies(['127.0.0.1']);

        self::assertSame(204, $this->guarded($config, '127.0.0.1', ['X-Forwarded-For' => '203.0.113.7'])->getStatusCode());
        self::assertSame(403, $this->guarded($config, '127.0.0.1', ['X-Forwarded-For' => '198.51.100.4'])->getStatusCode(), 'a proxied client is not admitted because the proxy is on loopback');
        self::assertSame(204, $this->guarded($config, '127.0.0.1')->getStatusCode(), 'the proxy itself, forwarding nothing, is loopback');

        $untrusted = self::config('203.0.113.7');
        self::assertSame(403, $this->guarded($untrusted, '198.51.100.4', ['X-Forwarded-For' => '203.0.113.7'])->getStatusCode(), 'an untrusted peer cannot claim a listed address');
    }

    public function testAMalformedEntryIsRefusedAtConfiguration(): void
    {
        foreach (['example.com', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/x', ''] as $bad) {
            try {
                self::config($bad);
                self::fail('accepted ' . $bad);
            } catch (ServerConfigException $e) {
                self::assertStringContainsString('is not an IP address or CIDR range', $e->getMessage());
            }
        }
    }

    public function testRangeHelpers(): void
    {
        self::assertSame('192.0.2.7', ClientAddress::normalise('::ffff:192.0.2.7'));
        self::assertSame('2001:db8::1', ClientAddress::normalise('2001:DB8:0::1'));
        self::assertSame('', ClientAddress::normalise('not-an-ip'));
        self::assertSame('10.0.0.0/8', ClientAddress::normaliseRange(' 10.0.0.0/8 '));
        self::assertNull(ClientAddress::normaliseRange('10.0.0.0/8/8'));
        self::assertTrue(ClientAddress::inAny('::ffff:10.0.0.1', ['10.0.0.0/8']), 'a mapped trusted proxy matches its IPv4 entry too');
    }
}

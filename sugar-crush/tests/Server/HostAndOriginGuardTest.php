<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Crush\Server\Http\HostAndOriginGuard;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * Appendix O §8.3: the Host allow-list (DNS rebinding) and the Origin check on
 * upgrades and state-changing requests (CSWSH / CSRF).
 */
final class HostAndOriginGuardTest extends TestCase
{
    private ?ServerRequestInterface $passed = null;

    /** @param array<string, string> $headers */
    private function guarded(ServerConfig $config, string $method, string $path, array $headers): ResponseInterface
    {
        $this->passed = null;
        $guard = new HostAndOriginGuard($config);
        $result = $guard(new ServerRequest($method, 'http://placeholder' . $path, $headers), function (ServerRequestInterface $request): ResponseInterface {
            $this->passed = $request;

            return new Response(204);
        });
        self::assertInstanceOf(ResponseInterface::class, $result);

        return $result;
    }

    private static function config(): ServerConfig
    {
        return ServerConfig::new('/nonexistent');
    }

    public function testLoopbackHostNamesAreAnsweredOnAnyPort(): void
    {
        foreach (['127.0.0.1:7420', 'localhost:7420', '[::1]:7420', 'localhost', 'LOCALHOST:9'] as $host) {
            self::assertSame(204, $this->guarded(self::config(), 'GET', '/', ['Host' => $host])->getStatusCode(), $host);
            self::assertSame(HostAndOriginGuard::ORIGIN_UNCHECKED, $this->passed?->getAttribute(HostAndOriginGuard::ATTR_ORIGIN));
        }
    }

    public function testARebindingHostNameIsRefused421(): void
    {
        $response = $this->guarded(self::config(), 'GET', '/', ['Host' => 'evil.example:7420']);

        self::assertSame(421, $response->getStatusCode());
        self::assertNull($this->passed);
        self::assertStringContainsString('host_refused', (string) $response->getBody());
    }

    public function testAMissingOrMalformedHostIsRefused(): void
    {
        self::assertSame(421, $this->guarded(self::config(), 'GET', '/', ['Host' => 'a b'])->getStatusCode());
        self::assertSame(421, $this->guarded(self::config(), 'GET', '/', ['Host' => 'x@127.0.0.1'])->getStatusCode());
    }

    public function testAllowedHostsAndARemoteBindAddressAreAnswered(): void
    {
        $config = self::config()->withAllowedHosts(['agent.example.com', 'other.example:8443']);
        self::assertSame(204, $this->guarded($config, 'GET', '/', ['Host' => 'agent.example.com'])->getStatusCode());
        self::assertSame(204, $this->guarded($config, 'GET', '/', ['Host' => 'other.example:8443'])->getStatusCode());
        self::assertSame(421, $this->guarded($config, 'GET', '/', ['Host' => 'other.example:9999'])->getStatusCode(), 'a host:port entry pins the port');

        $remote = self::config()->withHost('192.168.7.9')->withAllowRemote(true);
        self::assertSame(204, $this->guarded($remote, 'GET', '/', ['Host' => '192.168.7.9:7420'])->getStatusCode());
        self::assertSame(421, $this->guarded(self::config(), 'GET', '/', ['Host' => '192.168.7.9:7420'])->getStatusCode(), 'a loopback server does not answer to a LAN address');
    }

    public function testAnAllowedHostFlagIsAnsweredAndEverythingElseStillRefused(): void
    {
        $config = ServerConfig::resolve(['--allowed-host' => 'dev.example.com,[2001:db8::7]:7420'], [], [], '/nonexistent');

        self::assertSame(204, $this->guarded($config, 'GET', '/', ['Host' => 'dev.example.com:7420'])->getStatusCode());
        self::assertSame(204, $this->guarded($config, 'GET', '/', ['Host' => '[2001:db8::7]:7420'])->getStatusCode());
        self::assertSame(421, $this->guarded($config, 'GET', '/', ['Host' => 'evil.example:7420'])->getStatusCode());
    }

    public function testAWildcardRemoteBindAnswersToThisMachinesOwnAddresses(): void
    {
        $wildcard = self::config()->withHost('0.0.0.0')->withAllowRemote(true)->withInterfaceAddresses(['69.10.33.243', '10.0.0.5', '2001:db8::1']);

        foreach (['69.10.33.243:7420', '10.0.0.5:7420', '0.0.0.0:7420', 'localhost:7420'] as $host) {
            self::assertSame(204, $this->guarded($wildcard, 'GET', '/', ['Host' => $host])->getStatusCode(), $host);
        }
        self::assertSame(421, $this->guarded($wildcard, 'GET', '/', ['Host' => '[2001:db8::1]:7420'])->getStatusCode(), '0.0.0.0 is not reached over IPv6');
        self::assertSame(421, $this->guarded($wildcard, 'GET', '/', ['Host' => '69.10.33.244:7420'])->getStatusCode(), 'not an address of this machine');
        self::assertSame(421, $this->guarded($wildcard, 'GET', '/', ['Host' => 'evil.example:7420'])->getStatusCode(), 'still no rebinding');

        $v6 = $wildcard->withHost('::');
        self::assertSame(204, $this->guarded($v6, 'GET', '/', ['Host' => '[2001:db8::1]:7420'])->getStatusCode());
        self::assertSame(204, $this->guarded($v6, 'GET', '/', ['Host' => '69.10.33.243:7420'])->getStatusCode());

        $oneInterface = $wildcard->withHost('10.0.0.5');
        self::assertSame(421, $this->guarded($oneInterface, 'GET', '/', ['Host' => '69.10.33.243:7420'])->getStatusCode(), 'a single-address bind answers to that address only');
    }

    public function testTheRefusalNamesTheHostAndTheFixInTheResponseAndTheLog(): void
    {
        $logged = [];
        $guard = new HostAndOriginGuard(self::config(), static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $response = $guard(new ServerRequest('GET', 'http://placeholder/', ['Host' => 'Dev.Example.com:7420']), static fn (): ResponseInterface => new Response(204));
        self::assertInstanceOf(ResponseInterface::class, $response);

        self::assertSame(421, $response->getStatusCode());
        $body = \json_decode((string) $response->getBody(), true);
        self::assertSame('host_refused', $body['error']['kind'] ?? null, 'the kind clients key on is unchanged');
        self::assertSame(
            'this server does not answer to host "dev.example.com"; start it with --allowed-host dev.example.com or add it to server.allowedHosts',
            $body['error']['message'] ?? null,
        );
        self::assertSame(['refused host "dev.example.com": start it with --allowed-host dev.example.com or add it to server.allowedHosts'], $logged);
    }

    public function testTheServersOwnOriginPassesAnUpgradeAndAPost(): void
    {
        $upgrade = ['Host' => '127.0.0.1:7420', 'Upgrade' => 'websocket', 'Origin' => 'http://127.0.0.1:7420'];
        self::assertSame(204, $this->guarded(self::config(), 'GET', '/ws', $upgrade)->getStatusCode());
        self::assertSame(HostAndOriginGuard::ORIGIN_SAME, $this->passed?->getAttribute(HostAndOriginGuard::ATTR_ORIGIN));

        $post = ['Host' => 'localhost:7420', 'Origin' => 'HTTP://LOCALHOST:7420'];
        self::assertSame(204, $this->guarded(self::config(), 'POST', '/api/login', $post)->getStatusCode());
        self::assertSame(HostAndOriginGuard::ORIGIN_SAME, $this->passed?->getAttribute(HostAndOriginGuard::ATTR_ORIGIN));
    }

    public function testACrossSiteOriginIsRefused403OnUpgradesAndPostsOnly(): void
    {
        foreach (['https://evil.example', 'null', 'http://127.0.0.1:7421', 'http://127.0.0.1:7420/path'] as $origin) {
            $response = $this->guarded(self::config(), 'GET', '/ws', ['Host' => '127.0.0.1:7420', 'Upgrade' => 'websocket', 'Origin' => $origin]);
            self::assertSame(403, $response->getStatusCode(), $origin);
            self::assertStringContainsString('origin_refused', (string) $response->getBody());

            self::assertSame(403, $this->guarded(self::config(), 'POST', '/api/ticket', ['Host' => '127.0.0.1:7420', 'Origin' => $origin])->getStatusCode(), $origin);
        }

        // A plain GET of the UI is not state-changing; its Origin is not consulted.
        self::assertSame(204, $this->guarded(self::config(), 'GET', '/', ['Host' => '127.0.0.1:7420', 'Origin' => 'https://evil.example'])->getStatusCode());
    }

    public function testAnAllowedOriginPassesWithItsOwnTag(): void
    {
        $config = self::config()->withAllowedOrigins(['https://ui.example.com:443', 'http://dev.local:5173']);

        self::assertSame(['https://ui.example.com', 'http://dev.local:5173'], $config->allowedOrigins, 'default ports are dropped');
        self::assertSame(204, $this->guarded($config, 'POST', '/api/login', ['Host' => '127.0.0.1:7420', 'Origin' => 'https://ui.example.com'])->getStatusCode());
        self::assertSame(HostAndOriginGuard::ORIGIN_ALLOWED, $this->passed?->getAttribute(HostAndOriginGuard::ATTR_ORIGIN));
        self::assertSame(204, $this->guarded($config, 'GET', '/ws', ['Host' => '127.0.0.1:7420', 'Upgrade' => 'websocket', 'Origin' => 'http://dev.local:5173'])->getStatusCode());
    }

    public function testAMissingOriginIsLetThroughButRecordedForTheAuthLayer(): void
    {
        self::assertSame(204, $this->guarded(self::config(), 'GET', '/ws', ['Host' => '127.0.0.1:7420', 'Upgrade' => 'websocket'])->getStatusCode());
        self::assertSame(HostAndOriginGuard::ORIGIN_ABSENT, $this->passed?->getAttribute(HostAndOriginGuard::ATTR_ORIGIN));
    }

    public function testOriginNormalisation(): void
    {
        self::assertSame('http://a.example', HostAndOriginGuard::normaliseOrigin('http://A.example:80'));
        self::assertSame('https://a.example:8443', HostAndOriginGuard::normaliseOrigin('https://a.example:8443/'));
        self::assertSame('http://[::1]:7420', HostAndOriginGuard::normaliseOrigin('http://[::1]:7420'));
        self::assertNull(HostAndOriginGuard::normaliseOrigin('null'));
        self::assertNull(HostAndOriginGuard::normaliseOrigin('file://x'));
        self::assertNull(HostAndOriginGuard::normaliseOrigin('http://a.example/x'));
    }
}

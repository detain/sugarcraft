<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    public function testToSshArgvBare(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com');
        $this->assertSame(['ssh', '--', 'prod.example.com'], $e->toSshArgv());
    }

    public function testToSshArgvWithUserPortIdentity(): void
    {
        $e = new Endpoint(
            name: 'prod', host: 'prod.example.com', port: 2222,
            user: 'deploy', identityFiles: ['/home/me/.ssh/prod'],
        );
        $this->assertSame(
            ['ssh', '-p', '2222', '-i', '/home/me/.ssh/prod', '--', 'deploy@prod.example.com'],
            $e->toSshArgv(),
        );
    }

    public function testToSshArgvWithOptions(): void
    {
        $e = new Endpoint(
            name: 'jump', host: 'bastion.example.com',
            options: ['ServerAliveInterval=30', 'ProxyJump=gw.example.com'],
        );
        $argv = $e->toSshArgv();
        $this->assertContains('-o', $argv);
        $this->assertContains('ServerAliveInterval=30', $argv);
        $this->assertContains('ProxyJump=gw.example.com', $argv);
    }

    public function testCustomBinaryPath(): void
    {
        $e = new Endpoint(name: 'a', host: 'a.test');
        $this->assertSame(['/usr/local/bin/ssh', '--', 'a.test'], $e->toSshArgv('/usr/local/bin/ssh'));
    }

    public function testDisplayLineFormatting(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', port: 2222, user: 'deploy');
        $this->assertStringContainsString('prod', $e->displayLine());
        $this->assertStringContainsString('deploy@prod.example.com:2222', $e->displayLine());
    }

    public function testDisplayLineDefaultPort(): void
    {
        $e = new Endpoint(name: 'a', host: 'a.test', user: 'me');
        $line = $e->displayLine();
        $this->assertStringContainsString('me@a.test', $line);
        $this->assertStringNotContainsString(':22', $line);
    }

    public function testConstructorRefusesOutOfRangePorts(): void
    {
        foreach ([0, -1, 65536, 99999999999] as $port) {
            try {
                new Endpoint(name: 'edge', host: 'e.test', port: $port);
                $this->fail("port {$port} must be refused");
            } catch (\RuntimeException $e) {
                // The error names the host so a broken config row is findable.
                $this->assertStringContainsString('edge', $e->getMessage());
            }
        }
    }

    public function testConstructorAcceptsPortBoundaries(): void
    {
        $this->assertSame(1, (new Endpoint(name: 'lo', host: 'l.test', port: 1))->port);
        $this->assertSame(65535, (new Endpoint(name: 'hi', host: 'h.test', port: 65535))->port);
    }

    public function testWithPortGoesThroughTheSameGuard(): void
    {
        // mutate() rebuilds via the constructor, so withPort cannot smuggle
        // an unrepresentable port past the boundary check.
        $e = new Endpoint(name: 'a', host: 'a.test');
        $this->expectException(\RuntimeException::class);
        $e->withPort(-5);
    }

    public function testParsePortRefusesNonIntegerShapes(): void
    {
        // bool/float/strings with any non-digit must never reach ssh -p via
        // a silent (int) cast ("notanumber" → 0, true → 1).
        foreach (['notanumber', '', ' 22', '22abc', true, false, 22.5] as $raw) {
            try {
                Endpoint::parsePort('hosty', $raw);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('hosty', $e->getMessage());
                continue;
            }
            $this->fail('parsePort accepted ' . var_export($raw, true));
        }
    }

    public function testParsePortAcceptsCanonicalForms(): void
    {
        $this->assertSame(22, Endpoint::parsePort('h', 22));
        $this->assertSame(2222, Endpoint::parsePort('h', '2222'));
        $this->assertSame(65535, Endpoint::parsePort('h', '65535'));
    }
}

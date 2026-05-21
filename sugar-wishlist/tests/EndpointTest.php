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
        $this->assertSame(['ssh', 'prod.example.com'], $e->toSshArgv());
    }

    public function testToSshArgvWithUserPortIdentity(): void
    {
        $e = new Endpoint(
            name: 'prod', host: 'prod.example.com', port: 2222,
            user: 'deploy', identityFiles: ['/home/me/.ssh/prod'],
        );
        $this->assertSame(
            ['ssh', '-p', '2222', '-i', '/home/me/.ssh/prod', 'deploy@prod.example.com'],
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
        $this->assertSame(['/usr/local/bin/ssh', 'a.test'], $e->toSshArgv('/usr/local/bin/ssh'));
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
}

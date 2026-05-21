<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

final class EndpointProxyJumpTest extends TestCase
{
    public function testProxyJumpInToSshArgv(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: 'deploy',
            proxyJump: 'bastion.example.com',
        );
        $argv = $e->toSshArgv();
        $this->assertSame('ssh', $argv[0]);
        $this->assertSame('-J', $argv[1]);
        $this->assertSame('bastion.example.com', $argv[2]);
        $this->assertSame('deploy@prod.example.com', $argv[3]);
    }

    public function testProxyJumpWithPortInToSshArgv(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            port: 2222,
            user: 'deploy',
            proxyJump: 'bastion.example.com',
        );
        $argv = $e->toSshArgv();
        $this->assertSame(['ssh', '-p', '2222', '-J', 'bastion.example.com', 'deploy@prod.example.com'], $argv);
    }

    public function testProxyJumpWithIdentityFilesInToSshArgv(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: 'deploy',
            identityFiles: ['/path/to/key'],
            proxyJump: 'bastion.example.com',
        );
        $argv = $e->toSshArgv();
        $this->assertSame(['ssh', '-i', '/path/to/key', '-J', 'bastion.example.com', 'deploy@prod.example.com'], $argv);
    }

    public function testProxyJumpNullDoesNotAddFlag(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', proxyJump: null);
        $argv = $e->toSshArgv();
        $this->assertNotContains('-J', $argv);
    }

    public function testProxyJumpEmptyStringDoesNotAddFlag(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', proxyJump: '');
        $argv = $e->toSshArgv();
        $this->assertNotContains('-J', $argv);
    }

    public function testWithProxyJumpReturnsNewInstance(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com');
        $e2 = $e->withProxyJump('bastion.example.com');
        $this->assertNotSame($e, $e2);
        $this->assertNull($e->proxyJump);
        $this->assertSame('bastion.example.com', $e2->proxyJump);
    }
}
<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

final class EndpointIdentityFilesTest extends TestCase
{
    public function testEmptyIdentityFilesDoesNotAddFlag(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', identityFiles: []);
        $argv = $e->toSshArgv();
        $this->assertNotContains('-i', $argv);
    }

    public function testMultipleIdentityFilesEmitOneFlagEachInOrder(): void
    {
        // Regression: toSshArgv() previously emitted only identityFiles[0],
        // silently dropping every subsequent key. Each file must now produce
        // its own `-i <file>` pair, preserving the configured order.
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: 'deploy',
            identityFiles: ['/path/to/key1', '/path/to/key2', '/path/to/key3'],
        );
        $argv = $e->toSshArgv();
        $this->assertSame(
            ['ssh', '-i', '/path/to/key1', '-i', '/path/to/key2', '-i', '/path/to/key3', '--', 'deploy@prod.example.com'],
            $argv,
        );
    }

    public function testEmptyStringIdentityFileEntriesAreSkipped(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: 'deploy',
            identityFiles: ['', '/path/to/key', ''],
        );
        $argv = $e->toSshArgv();
        $this->assertSame(['ssh', '-i', '/path/to/key', '--', 'deploy@prod.example.com'], $argv);
    }

    public function testIdentityFilesSingleItem(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: 'deploy',
            identityFiles: ['/path/to/key'],
        );
        $argv = $e->toSshArgv();
        $this->assertSame(['ssh', '-i', '/path/to/key', '--', 'deploy@prod.example.com'], $argv);
    }

    public function testWithIdentityFilesReturnsNewInstance(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com');
        $e2 = $e->withIdentityFiles(['/path/to/key']);
        $this->assertNotSame($e, $e2);
        $this->assertSame([], $e->identityFiles);
        $this->assertSame(['/path/to/key'], $e2->identityFiles);
    }

    public function testIdentityFilesPreservesOtherFields(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            port: 2222,
            user: 'deploy',
            description: 'Production server',
            proxyJump: 'bastion.example.com',
            options: ['ServerAliveInterval=30'],
        );
        $e2 = $e->withIdentityFiles(['/path/to/key']);
        $this->assertSame('prod', $e2->name);
        $this->assertSame('prod.example.com', $e2->host);
        $this->assertSame(2222, $e2->port);
        $this->assertSame('deploy', $e2->user);
        $this->assertSame('Production server', $e2->description);
        $this->assertSame('bastion.example.com', $e2->proxyJump);
        $this->assertSame(['ServerAliveInterval=30'], $e2->options);
    }
}

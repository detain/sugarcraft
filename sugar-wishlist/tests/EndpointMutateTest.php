<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

/**
 * Tests that all with*() methods return new instances (immutability)
 * and correctly set only the targeted field.
 */
final class EndpointMutateTest extends TestCase
{
    private function baseEndpoint(): Endpoint
    {
        return new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            port: 22,
            user: 'admin',
            identityFiles: ['/path/to/key'],
            description: 'Production server',
            proxyJump: 'bastion.example.com',
            options: ['ServerAliveInterval=30'],
        );
    }

    public function testWithNameReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withName('staging');
        $this->assertNotSame($e, $e2);
        $this->assertSame('prod', $e->name);
        $this->assertSame('staging', $e2->name);
        // Other fields unchanged
        $this->assertSame('prod.example.com', $e2->host);
    }

    public function testWithHostReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withHost('stage.example.com');
        $this->assertNotSame($e, $e2);
        $this->assertSame('prod.example.com', $e->host);
        $this->assertSame('stage.example.com', $e2->host);
        $this->assertSame('prod', $e2->name);
    }

    public function testWithPortReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withPort(2222);
        $this->assertNotSame($e, $e2);
        $this->assertSame(22, $e->port);
        $this->assertSame(2222, $e2->port);
    }

    public function testWithUserReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withUser('deploy');
        $this->assertNotSame($e, $e2);
        $this->assertSame('admin', $e->user);
        $this->assertSame('deploy', $e2->user);
    }

    public function testWithUserNullReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withUser(null);
        $this->assertNotSame($e, $e2);
        $this->assertSame('admin', $e->user);
        $this->assertNull($e2->user);
    }

    public function testWithIdentityFilesReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withIdentityFiles(['/new/key']);
        $this->assertNotSame($e, $e2);
        $this->assertSame(['/path/to/key'], $e->identityFiles);
        $this->assertSame(['/new/key'], $e2->identityFiles);
    }

    public function testWithDescriptionReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withDescription('New description');
        $this->assertNotSame($e, $e2);
        $this->assertSame('Production server', $e->description);
        $this->assertSame('New description', $e2->description);
    }

    public function testWithDescriptionNullReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withDescription(null);
        $this->assertNotSame($e, $e2);
        $this->assertSame('Production server', $e->description);
        $this->assertNull($e2->description);
    }

    public function testWithProxyJumpReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withProxyJump('new-bastion.example.com');
        $this->assertNotSame($e, $e2);
        $this->assertSame('bastion.example.com', $e->proxyJump);
        $this->assertSame('new-bastion.example.com', $e2->proxyJump);
    }

    public function testWithProxyJumpNullReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withProxyJump(null);
        $this->assertNotSame($e, $e2);
        $this->assertSame('bastion.example.com', $e->proxyJump);
        $this->assertNull($e2->proxyJump);
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e->withOptions(['StrictHostKeyChecking=no']);
        $this->assertNotSame($e, $e2);
        $this->assertSame(['ServerAliveInterval=30'], $e->options);
        $this->assertSame(['StrictHostKeyChecking=no'], $e2->options);
    }

    public function testChainedWithCallsWork(): void
    {
        $e = $this->baseEndpoint();
        $e2 = $e
            ->withName('staging')
            ->withHost('stage.example.com')
            ->withPort(2222);
        $this->assertSame('prod', $e->name); // original unchanged
        $this->assertSame('staging', $e2->name);
        $this->assertSame('stage.example.com', $e2->host);
        $this->assertSame(2222, $e2->port);
    }
}

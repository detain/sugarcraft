<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

final class EndpointDisplayLineTest extends TestCase
{
    public function testDisplayLineWithNullUserAndCustomPort(): void
    {
        // When user is null, displayLine should show host:port without user@
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', port: 2222, user: null);
        $line = $e->displayLine();
        $this->assertSame('prod  ─  prod.example.com:2222', $line);
    }

    public function testDisplayLineWithEmptyStringUserAndCustomPort(): void
    {
        // Empty string user is different from null — same outcome (no user@ prefix)
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', port: 2222, user: '');
        $line = $e->displayLine();
        $this->assertSame('prod  ─  prod.example.com:2222', $line);
    }

    public function testDisplayLineWithUserAndDefaultPortOmitsPort(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', user: 'admin');
        $line = $e->displayLine();
        $this->assertStringContainsString('admin@prod.example.com', $line);
        $this->assertStringNotContainsString(':22', $line);
    }

    public function testDisplayLineWithNullUserAndDefaultPortOmitsPort(): void
    {
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', port: 22, user: null);
        $line = $e->displayLine();
        $this->assertStringContainsString('prod.example.com', $line);
        $this->assertStringNotContainsString(':22', $line);
    }

    public function testDisplayLineFormatIsNameThenDashedSeparatorsThenDestination(): void
    {
        $e = new Endpoint(name: 'my-server', host: 'server.example.com', port: 3333, user: 'bob');
        $line = $e->displayLine();
        // Exact format: "name  ─  user@host:port"
        $this->assertSame('my-server  ─  bob@server.example.com:3333', $line);
    }

    public function testDisplayLineWithDescriptionNotIncluded(): void
    {
        // description is not part of displayLine — it is only shown in the Picker draw output
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            description: 'Should not appear in displayLine',
        );
        $line = $e->displayLine();
        $this->assertStringNotContainsString('Should not appear', $line);
    }
}

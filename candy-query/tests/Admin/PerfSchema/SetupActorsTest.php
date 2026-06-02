<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupActors;

final class SetupActorsTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $actor = SetupActors::new(
            host: "'%'",
            user: "'root'",
            role: "'%'",
            enabled: true,
        );

        $this->assertSame("'%'", $actor->host);
        $this->assertSame("'root'", $actor->user);
        $this->assertSame("'%'", $actor->role);
        $this->assertTrue($actor->enabled);
    }

    public function testWithEnabledReturnsNewInstance(): void
    {
        $original = SetupActors::new(
            host: "'%'",
            user: "'root'",
            role: "'%'",
            enabled: true,
        );

        $modified = $original->withEnabled(false);

        $this->assertTrue($original->enabled);
        $this->assertFalse($modified->enabled);
    }

    public function testIsGlobalHost(): void
    {
        $globalHost = SetupActors::new(host: "'%'");
        $specificHost = SetupActors::new(host: "'localhost'");

        $this->assertTrue($globalHost->isGlobalHost());
        $this->assertFalse($specificHost->isGlobalHost());
    }

    public function testIsGlobalHostWithoutQuotes(): void
    {
        $globalHost = SetupActors::new(host: '%');

        $this->assertTrue($globalHost->isGlobalHost());
    }

    public function testIsGlobalUser(): void
    {
        $globalUser = SetupActors::new(user: "'%'");
        $specificUser = SetupActors::new(user: "'root'");

        $this->assertTrue($globalUser->isGlobalUser());
        $this->assertFalse($specificUser->isGlobalUser());
    }

    public function testIsGlobalRole(): void
    {
        $globalRole = SetupActors::new(role: "'%'");
        $specificRole = SetupActors::new(role: "'admin'");

        $this->assertTrue($globalRole->isGlobalRole());
        $this->assertFalse($specificRole->isGlobalRole());
    }

    public function testIsCatchAll(): void
    {
        $catchAll = SetupActors::new(
            host: "'%'",
            user: "'%'",
            role: "'%'",
        );
        $notCatchAll = SetupActors::new(
            host: "'%'",
            user: "'root'",
            role: "'%'",
        );

        $this->assertTrue($catchAll->isCatchAll());
        $this->assertFalse($notCatchAll->isCatchAll());
    }
}

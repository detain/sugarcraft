<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TeamConfig;

/**
 * Tests for TeamConfig - team configuration.
 */
final class TeamConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Default values
    // -------------------------------------------------------------------------

    public function testDefaultMaxTeammates(): void
    {
        $config = new TeamConfig();
        $this->assertSame(5, $config->maxTeammates);
    }

    public function testDefaultTimeoutSeconds(): void
    {
        $config = new TeamConfig();
        $this->assertSame(600, $config->defaultTimeoutSeconds);
    }

    public function testDefaultAllowPeerMessaging(): void
    {
        $config = new TeamConfig();
        $this->assertTrue($config->allowPeerMessaging);
    }

    public function testDefaultAutoAssignTasks(): void
    {
        $config = new TeamConfig();
        $this->assertTrue($config->autoAssignTasks);
    }

    public function testDefaultInboxPath(): void
    {
        $config = new TeamConfig();
        $this->assertSame('~/.sugar-crush/teams/', $config->inboxPath);
    }

    // -------------------------------------------------------------------------
    // Custom values via constructor
    // -------------------------------------------------------------------------

    public function testCustomValues(): void
    {
        $config = new TeamConfig(
            maxTeammates: 10,
            defaultTimeoutSeconds: 1200,
            allowPeerMessaging: false,
            autoAssignTasks: false,
            inboxPath: '/var/teams/',
        );

        $this->assertSame(10, $config->maxTeammates);
        $this->assertSame(1200, $config->defaultTimeoutSeconds);
        $this->assertFalse($config->allowPeerMessaging);
        $this->assertFalse($config->autoAssignTasks);
        $this->assertSame('/var/teams/', $config->inboxPath);
    }

    // -------------------------------------------------------------------------
    // withMaxTeammates()
    // -------------------------------------------------------------------------

    public function testWithMaxTeammates(): void
    {
        $original = new TeamConfig(maxTeammates: 5);
        $modified = $original->withMaxTeammates(10);

        $this->assertSame(5, $original->maxTeammates);
        $this->assertSame(10, $modified->maxTeammates);
    }

    public function testWithMaxTeammatesPreservesOtherFields(): void
    {
        $original = new TeamConfig(
            maxTeammates: 5,
            defaultTimeoutSeconds: 1200,
            allowPeerMessaging: false,
            autoAssignTasks: false,
            inboxPath: '/custom/path/',
        );
        $modified = $original->withMaxTeammates(20);

        $this->assertSame(5, $original->maxTeammates);
        $this->assertSame(20, $modified->maxTeammates);
        $this->assertSame(1200, $modified->defaultTimeoutSeconds);
        $this->assertFalse($modified->allowPeerMessaging);
        $this->assertFalse($modified->autoAssignTasks);
        $this->assertSame('/custom/path/', $modified->inboxPath);
    }

    // -------------------------------------------------------------------------
    // withDefaultTimeoutSeconds()
    // -------------------------------------------------------------------------

    public function testWithDefaultTimeoutSeconds(): void
    {
        $original = new TeamConfig(defaultTimeoutSeconds: 600);
        $modified = $original->withDefaultTimeoutSeconds(3600);

        $this->assertSame(600, $original->defaultTimeoutSeconds);
        $this->assertSame(3600, $modified->defaultTimeoutSeconds);
    }

    // -------------------------------------------------------------------------
    // withAllowPeerMessaging()
    // -------------------------------------------------------------------------

    public function testWithAllowPeerMessagingTrue(): void
    {
        $original = new TeamConfig(allowPeerMessaging: false);
        $modified = $original->withAllowPeerMessaging(true);

        $this->assertFalse($original->allowPeerMessaging);
        $this->assertTrue($modified->allowPeerMessaging);
    }

    public function testWithAllowPeerMessagingFalse(): void
    {
        $original = new TeamConfig(allowPeerMessaging: true);
        $modified = $original->withAllowPeerMessaging(false);

        $this->assertTrue($original->allowPeerMessaging);
        $this->assertFalse($modified->allowPeerMessaging);
    }

    // -------------------------------------------------------------------------
    // withAutoAssignTasks()
    // -------------------------------------------------------------------------

    public function testWithAutoAssignTasksTrue(): void
    {
        $original = new TeamConfig(autoAssignTasks: false);
        $modified = $original->withAutoAssignTasks(true);

        $this->assertFalse($original->autoAssignTasks);
        $this->assertTrue($modified->autoAssignTasks);
    }

    public function testWithAutoAssignTasksFalse(): void
    {
        $original = new TeamConfig(autoAssignTasks: true);
        $modified = $original->withAutoAssignTasks(false);

        $this->assertTrue($original->autoAssignTasks);
        $this->assertFalse($modified->autoAssignTasks);
    }

    // -------------------------------------------------------------------------
    // withInboxPath()
    // -------------------------------------------------------------------------

    public function testWithInboxPath(): void
    {
        $original = new TeamConfig(inboxPath: '~/.teams/');
        $modified = $original->withInboxPath('/tmp/teams/');

        $this->assertSame('~/.teams/', $original->inboxPath);
        $this->assertSame('/tmp/teams/', $modified->inboxPath);
    }

    // -------------------------------------------------------------------------
    // Immutability - with*() returns new instance
    // -------------------------------------------------------------------------

    public function testWithMethodsReturnNewInstance(): void
    {
        $original = new TeamConfig();

        $this->assertNotSame($original, $original->withMaxTeammates(10));
        $this->assertNotSame($original, $original->withDefaultTimeoutSeconds(600));
        $this->assertNotSame($original, $original->withAllowPeerMessaging(false));
        $this->assertNotSame($original, $original->withAutoAssignTasks(false));
        $this->assertNotSame($original, $original->withInboxPath('/new/path/'));
    }

    // -------------------------------------------------------------------------
    // Zero values are valid
    // -------------------------------------------------------------------------

    public function testZeroMaxTeammates(): void
    {
        $config = new TeamConfig(maxTeammates: 0);
        $this->assertSame(0, $config->maxTeammates);
    }

    public function testZeroTimeoutSeconds(): void
    {
        $config = new TeamConfig(defaultTimeoutSeconds: 0);
        $this->assertSame(0, $config->defaultTimeoutSeconds);
    }

    // -------------------------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------------------------

    public function testLargeMaxTeammates(): void
    {
        $config = new TeamConfig(maxTeammates: 100);
        $this->assertSame(100, $config->maxTeammates);
    }

    public function testLargeTimeoutSeconds(): void
    {
        $config = new TeamConfig(defaultTimeoutSeconds: 86400);
        $this->assertSame(86400, $config->defaultTimeoutSeconds);
    }

    public function testEmptyInboxPath(): void
    {
        $config = new TeamConfig(inboxPath: '');
        $this->assertSame('', $config->inboxPath);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for SSH argv injection protection.
 *
 * Verifies that leading-dash values in proxyJump, options, or host/user
 * are either rejected with a clear error or neutralized by the `--`
 * separator so they cannot be interpreted as ssh flags.
 */
final class EndpointArgvSafetyTest extends TestCase
{
    public function testDestinationIsAfterDoubleDash(): void
    {
        // The `--` separator is placed immediately before the destination,
        // so ssh treats everything after it as an operand, not an option.
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', user: 'admin');
        $argv = $e->toSshArgv();
        // Find the '--' index and confirm destination follows it
        $dashIdx = array_search('--', $argv, true);
        $this->assertNotFalse($dashIdx, '-- separator must be present');
        $destIdx = $dashIdx + 1;
        $this->assertArrayHasKey($destIdx, $argv, 'destination must follow --');
        $this->assertSame('admin@prod.example.com', $argv[$destIdx]);
    }

    public function testProxyJumpLeadingDashThrows(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            proxyJump: '-oProxyCommand=calc',
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testOptionLeadingDashThrows(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            options: ['-oProxyCommand=calc'],
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testUserLeadingDashThrows(): void
    {
        // A leading-dash user becomes part of user@host and would be
        // an injected flag without the assertNotOption guard.
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: '-x',
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testNormalEndpointArgvUnchangedExceptForDoubleDash(): void
    {
        // A normal endpoint without any dash-leading fields should have
        // the same argv as before, except for the new trailing -- before
        // the destination.
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            port: 2222,
            user: 'deploy',
            identityFiles: ['/path/to/key'],
        );
        $argv = $e->toSshArgv();
        $expected = [
            'ssh',
            '-p', '2222',
            '-i', '/path/to/key',
            '--',
            'deploy@prod.example.com',
        ];
        $this->assertSame($expected, $argv);
    }
}

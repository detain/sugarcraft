<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\ParallelSafeCall;

final class BashParallelAndLifetimeTest extends TestCase
{
    public function testBashDecidesParallelSafetyPerCall(): void
    {
        $bash = new Bash();
        self::assertInstanceOf(ParallelSafeCall::class, $bash);

        self::assertTrue($bash->isParallelSafeFor(['command' => 'git status']));
        self::assertTrue($bash->isParallelSafeFor(['command' => 'grep -rn TODO src', 'run_in_background' => false]));

        self::assertFalse($bash->isParallelSafeFor(['command' => 'rm -rf build']));
        self::assertFalse($bash->isParallelSafeFor(['command' => 'ls', 'run_in_background' => true]));
        self::assertFalse($bash->isParallelSafeFor(['command' => 'ls', 'run_in_background' => 'yes']));
        self::assertFalse($bash->isParallelSafeFor(['command' => 'ls', 'interactive' => true]));
        self::assertFalse($bash->isParallelSafeFor(['command' => 42]));
        self::assertFalse($bash->isParallelSafeFor([]));
    }

    public function testTheLifetimeArgumentIsClampedAndDefaultsToThirtyMinutes(): void
    {
        self::assertSame(1800, BackgroundShellRegistry::clampLifetime(null));
        self::assertSame(60, BackgroundShellRegistry::clampLifetime(10));
        self::assertSame(300, BackgroundShellRegistry::clampLifetime(300));
        self::assertSame(21600, BackgroundShellRegistry::clampLifetime(999_999));
    }

    public function testTheSchemaAdvertisesTheLifetimeBounds(): void
    {
        $schema = (new Bash())->inputSchema();

        self::assertArrayHasKey('lifetime_seconds', $schema['properties']);
        self::assertSame(60, $schema['properties']['lifetime_seconds']['minimum']);
        self::assertSame(21600, $schema['properties']['lifetime_seconds']['maximum']);
    }
}

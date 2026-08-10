<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use SugarCraft\Crush\Commands\CommandRegistry;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function testAllReturnsEveryDispatchedCommand(): void
    {
        $names = array_map(static fn($spec) => $spec->name, CommandRegistry::all());

        foreach (['compact', 'workflow', 'share', 'agents', 'memory', 'branch', 'rename', 'rewind', 'sessions', 'theme'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testFilterIsCaseInsensitivePrefixMatch(): void
    {
        $names = array_map(static fn($spec) => $spec->name, CommandRegistry::filter('RE'));
        $this->assertSame(['rename', 'rewind'], $names);
    }

    public function testFilterWithEmptyPrefixReturnsEverything(): void
    {
        $this->assertCount(count(CommandRegistry::all()), CommandRegistry::filter(''));
    }

    public function testFilterWithNoMatchesReturnsEmptyList(): void
    {
        $this->assertSame([], CommandRegistry::filter('zzz'));
    }
}

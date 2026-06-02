<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupConsumers;

final class SetupConsumersTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $consumer = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $this->assertSame('events_statements_history', $consumer->name);
        $this->assertTrue($consumer->enabled);
    }

    public function testWithEnabledReturnsNewInstance(): void
    {
        $original = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $modified = $original->withEnabled(false);

        $this->assertTrue($original->enabled);
        $this->assertFalse($modified->enabled);
        $this->assertSame('events_statements_history', $modified->name);
    }

    public function testImmutability(): void
    {
        $consumer = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $modified = $consumer->withEnabled(false);

        $this->assertTrue($consumer->enabled);
        $this->assertFalse($modified->enabled);
    }
}

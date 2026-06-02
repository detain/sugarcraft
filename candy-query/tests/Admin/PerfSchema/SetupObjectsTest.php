<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupObjects;

final class SetupObjectsTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $object = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mysql'",
            objectName: "'%'",
            enabled: true,
            timed: true,
        );

        $this->assertSame('TABLE', $object->objectType);
        $this->assertSame("'mysql'", $object->objectSchema);
        $this->assertSame("'%'", $object->objectName);
        $this->assertTrue($object->enabled);
        $this->assertTrue($object->timed);
    }

    public function testWithEnabledReturnsNewInstance(): void
    {
        $original = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mysql'",
            objectName: "'%'",
            enabled: true,
        );

        $modified = $original->withEnabled(false);

        $this->assertTrue($original->enabled);
        $this->assertFalse($modified->enabled);
    }

    public function testWithTimedReturnsNewInstance(): void
    {
        $original = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mysql'",
            objectName: "'%'",
            timed: true,
        );

        $modified = $original->withTimed(false);

        $this->assertTrue($original->timed);
        $this->assertFalse($modified->timed);
    }

    public function testIsGlobalSchema(): void
    {
        $global = SetupObjects::new(objectSchema: "'%'");
        $specific = SetupObjects::new(objectSchema: "'mysql'");

        $this->assertTrue($global->isGlobalSchema());
        $this->assertFalse($specific->isGlobalSchema());
    }

    public function testIsGlobalSchemaWithoutQuotes(): void
    {
        $global = SetupObjects::new(objectSchema: '%');

        $this->assertTrue($global->isGlobalSchema());
    }

    public function testIsGlobalObject(): void
    {
        $global = SetupObjects::new(objectName: "'%'");
        $specific = SetupObjects::new(objectName: "'users'");

        $this->assertTrue($global->isGlobalObject());
        $this->assertFalse($specific->isGlobalObject());
    }

    public function testIsCatchAll(): void
    {
        $catchAll = SetupObjects::new(
            objectSchema: "'%'",
            objectName: "'%'",
        );
        $notCatchAll = SetupObjects::new(
            objectSchema: "'%'",
            objectName: "'users'",
        );

        $this->assertTrue($catchAll->isCatchAll());
        $this->assertFalse($notCatchAll->isCatchAll());
    }
}

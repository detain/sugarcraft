<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupInstruments;

final class SetupInstrumentsTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $instrument = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
            properties: 'global,stat',
            flags: 'enabled',
        );

        $this->assertSame('wait/io/file/sql/binlog', $instrument->name);
        $this->assertTrue($instrument->enabled);
        $this->assertTrue($instrument->timed);
        $this->assertSame('global,stat', $instrument->properties);
        $this->assertSame('enabled', $instrument->flags);
    }

    public function testWithEnabledReturnsNewInstance(): void
    {
        $original = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        );

        $modified = $original->withEnabled(false);

        // Original unchanged
        $this->assertTrue($original->enabled);
        // New instance has new value
        $this->assertFalse($modified->enabled);
        // Other fields preserved
        $this->assertSame('wait/io/file/sql/binlog', $modified->name);
        $this->assertTrue($modified->timed);
    }

    public function testWithTimedReturnsNewInstance(): void
    {
        $original = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        );

        $modified = $original->withTimed(false);

        $this->assertTrue($original->timed);
        $this->assertFalse($modified->timed);
    }

    public function testHasProperty(): void
    {
        $instrument = SetupInstruments::new(
            name: 'statement/sql/select',
            properties: 'global,stat,abstract',
        );

        $this->assertTrue($instrument->hasProperty('global'));
        $this->assertTrue($instrument->hasProperty('stat'));
        $this->assertTrue($instrument->hasProperty('abstract'));
        $this->assertFalse($instrument->hasProperty('other'));
    }

    public function testHasPropertyWithEmptyProperties(): void
    {
        $instrument = SetupInstruments::new(name: 'test');

        $this->assertFalse($instrument->hasProperty('global'));
    }

    public function testIsAbstract(): void
    {
        $abstract = SetupInstruments::new(
            name: 'statement/abstract/new_packet',
            properties: 'abstract',
        );
        $concrete = SetupInstruments::new(
            name: 'statement/sql/select',
            properties: 'global',
        );

        $this->assertTrue($abstract->isAbstract());
        $this->assertFalse($concrete->isAbstract());
    }

    public function testIsGlobal(): void
    {
        $global = SetupInstruments::new(
            name: 'statement/sql/select',
            properties: 'global,stat',
        );
        $local = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            properties: 'stat',
        );

        $this->assertTrue($global->isGlobal());
        $this->assertFalse($local->isGlobal());
    }

    public function testImmutability(): void
    {
        $instrument = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        );

        $copy1 = $instrument->withEnabled(false);
        $copy2 = $copy1->withTimed(false);

        // Chain preserves all values
        $this->assertSame('wait/io/file/sql/binlog', $copy2->name);
        $this->assertFalse($copy2->enabled);
        $this->assertFalse($copy2->timed);

        // Original unchanged
        $this->assertTrue($instrument->enabled);
        $this->assertTrue($instrument->timed);
    }
}

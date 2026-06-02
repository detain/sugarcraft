<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\InstrumentTree;
use SugarCraft\Query\Admin\PerfSchema\SetupInstruments;

final class InstrumentTreeTest extends TestCase
{
    public function testFromInstrumentsCreatesTree(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file/sql/binlog', true, true),
            SetupInstruments::new('wait/io/file/sql/FRM', true, false),
            SetupInstruments::new('statement/sql/select', true, true),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);

        $this->assertNotNull($tree->child('wait'));
        $this->assertNotNull($tree->child('statement'));
    }

    public function testInsertAddsInstrument(): void
    {
        $tree = InstrumentTree::new();
        $instrument = SetupInstruments::new('wait/io/file/sql/binlog', true, true);

        $tree->insert($instrument);

        $waitNode = $tree->child('wait');
        $this->assertNotNull($waitNode);
        $this->assertNotNull($waitNode->child('io'));
    }

    public function testStateDisabledWhenAllDisabled(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', false, false),
            SetupInstruments::new('wait/io/socket', false, false),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);

        $this->assertSame(-1, $tree->state());
    }

    public function testStateEnabledWhenAllEnabled(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', true, true),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);

        $this->assertSame(1, $tree->state());
    }

    public function testStateMixedWhenMixed(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', false, false),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);

        $this->assertSame(0, $tree->state());
    }

    public function testEnabledReturnsBool(): void
    {
        $allEnabled = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait/io/file', true, true),
        ]);

        $allDisabled = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait/io/file', false, false),
        ]);

        $this->assertTrue($allEnabled->enabled());
        $this->assertFalse($allDisabled->enabled());
    }

    public function testTimedReturnsBool(): void
    {
        $timed = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait/io/file', true, true),
        ]);

        $notTimed = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait/io/file', true, false),
        ]);

        $this->assertTrue($timed->timed());
        $this->assertFalse($notTimed->timed());
    }

    public function testFindReturnsNode(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file/sql/binlog', true, true),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);

        $found = $tree->find('wait/io/file/sql/binlog');
        $this->assertNotNull($found);
        $this->assertSame('binlog', $found->name());
    }

    public function testFindReturnsNullForMissingPath(): void
    {
        $tree = InstrumentTree::fromInstruments([]);

        $this->assertNull($tree->find('nonexistent'));
    }

    public function testAllInstrumentsReturnsFlatList(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', true, true),
            SetupInstruments::new('statement/sql/select', true, true),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);
        $all = $tree->allInstruments();

        $this->assertCount(3, $all);
    }

    public function testChildrenReturnsAllChildren(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', true, true),
        ];

        $tree = InstrumentTree::fromInstruments($instruments);
        $waitNode = $tree->child('wait');
        $this->assertNotNull($waitNode);

        $ioNode = $waitNode->child('io');
        $this->assertNotNull($ioNode);
        $this->assertCount(2, $ioNode->children());
    }

    public function testHasChildren(): void
    {
        $tree = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait/io/file', true, true),
        ]);

        $waitNode = $tree->child('wait');
        $this->assertNotNull($waitNode);
        $this->assertTrue($waitNode->hasChildren());

        $ioNode = $waitNode->child('io');
        $this->assertNotNull($ioNode);
        $this->assertTrue($ioNode->hasChildren());

        $fileNode = $ioNode->child('file');
        $this->assertNotNull($fileNode);
        $this->assertFalse($fileNode->hasChildren());
    }

    public function testInstrumentReturnsSetupInstrument(): void
    {
        $instrument = SetupInstruments::new('wait/io/file/sql/binlog', true, true);
        $tree = InstrumentTree::fromInstruments([$instrument]);

        $found = $tree->find('wait/io/file/sql/binlog');
        $this->assertNotNull($found);

        $foundInstrument = $found->instrument();
        $this->assertNotNull($foundInstrument);
        $this->assertSame('wait/io/file/sql/binlog', $foundInstrument->name);
    }

    public function testDumpReturnsVisualRepresentation(): void
    {
        $tree = InstrumentTree::fromInstruments([
            SetupInstruments::new('wait', true, true),
        ]);

        $dump = $tree->dump();

        $this->assertStringContainsString('wait', $dump);
    }
}

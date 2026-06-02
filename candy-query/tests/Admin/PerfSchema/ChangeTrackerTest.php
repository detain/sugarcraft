<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\ChangeTracker;
use SugarCraft\Query\Admin\PerfSchema\SetupInstruments;
use SugarCraft\Query\Admin\PerfSchema\SetupConsumers;
use SugarCraft\Query\Admin\PerfSchema\SetupActors;
use SugarCraft\Query\Admin\PerfSchema\SetupObjects;

final class ChangeTrackerTest extends TestCase
{
    public function testNewTrackerIsEmpty(): void
    {
        $tracker = new ChangeTracker();

        $this->assertFalse($tracker->isDirty());
        $this->assertSame([], $tracker->diff());
    }

    public function testFromInstrumentsCreatesTracker(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        $this->assertFalse($tracker->isDirty());
        $this->assertNotNull($tracker->current('wait/io/file'));
    }

    public function testIsDirtyAfterModification(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        $modified = $tracker->current('wait/io/file')->withEnabled(false);
        $tracker->replace('wait/io/file', $modified);

        $this->assertTrue($tracker->isDirty());
    }

    public function testDiffReturnsChangedKeys(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        // Modify only one
        $modified = $tracker->current('wait/io/file')->withEnabled(false);
        $tracker->replace('wait/io/file', $modified);

        $diff = $tracker->diff();

        $this->assertContains('wait/io/file', $diff);
        $this->assertNotContains('wait/io/socket', $diff);
    }

    public function testResetRevertsChanges(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        // Make a change
        $modified = $tracker->current('wait/io/file')->withEnabled(false);
        $tracker->replace('wait/io/file', $modified);

        $this->assertTrue($tracker->isDirty());

        // Reset
        $tracker->reset();

        $this->assertFalse($tracker->isDirty());
        $current = $tracker->current('wait/io/file');
        $this->assertTrue($current->enabled);
    }

    public function testCommitMakesChangesPermanent(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        // Make a change
        $modified = $tracker->current('wait/io/file')->withEnabled(false);
        $tracker->replace('wait/io/file', $modified);

        // Commit
        $tracker->commit();

        $this->assertFalse($tracker->isDirty());

        // Original now reflects the committed change
        $original = $tracker->original('wait/io/file');
        $this->assertFalse($original->enabled);
    }

    public function testOriginalReturnsOriginalValue(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);

        // Modify
        $modified = $tracker->current('wait/io/file')->withEnabled(false);
        $tracker->replace('wait/io/file', $modified);

        // Original should still be true
        $original = $tracker->original('wait/io/file');
        $this->assertTrue($original->enabled);

        // Current should be false
        $current = $tracker->current('wait/io/file');
        $this->assertFalse($current->enabled);
    }

    public function testAllReturnsAllCurrentItems(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
            SetupInstruments::new('wait/io/socket', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);
        $all = $tracker->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('wait/io/file', $all);
        $this->assertArrayHasKey('wait/io/socket', $all);
    }

    public function testFromConsumersCreatesTracker(): void
    {
        $consumers = [
            SetupConsumers::new('events_statements_history', true),
        ];

        $tracker = ChangeTracker::fromConsumers($consumers);

        $this->assertFalse($tracker->isDirty());
        $this->assertNotNull($tracker->current('events_statements_history'));
    }

    public function testFromActorsCreatesTracker(): void
    {
        $actors = [
            SetupActors::new("'%'", "'root'", "'%'", true),
        ];

        $tracker = ChangeTracker::fromActors($actors);

        $this->assertFalse($tracker->isDirty());
        $this->assertNotNull($tracker->current("'%':'root':'%'"));
    }

    public function testFromObjectsCreatesTracker(): void
    {
        $objects = [
            SetupObjects::new('TABLE', "'mysql'", "'%'", true, true),
        ];

        $tracker = ChangeTracker::fromObjects($objects);

        $this->assertFalse($tracker->isDirty());
        $this->assertNotNull($tracker->current("TABLE:'mysql':'%'"));
    }

    public function testAddInsertsNewItem(): void
    {
        $tracker = new ChangeTracker();
        $tracker->add(SetupInstruments::new('wait/io/file', true, true));

        $this->assertNotNull($tracker->current('wait/io/file'));
    }

    public function testReplaceUpdatesCurrentValue(): void
    {
        $instruments = [
            SetupInstruments::new('wait/io/file', true, true),
        ];

        $tracker = ChangeTracker::fromInstruments($instruments);
        $newInstrument = SetupInstruments::new('wait/io/file', false, false);
        $tracker->replace('wait/io/file', $newInstrument);

        $current = $tracker->current('wait/io/file');
        $this->assertFalse($current->enabled);
        $this->assertFalse($current->timed);
    }
}
